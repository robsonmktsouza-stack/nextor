<?php
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Artisan;


Schedule::command('finance:generate-recurring')->dailyAt('00:10')->withoutOverlapping();
// One scheduler per isolated company installation; service enforces SEFAZ intervals.
Schedule::command('fiscal:sync-incoming-nfe')->everyFifteenMinutes()->withoutOverlapping();

Schedule::command('nextor:webhooks --limit=25')->everyMinute()->withoutOverlapping();

Schedule::command('nextor:accounting-export')->monthlyOn(1,'01:10')->withoutOverlapping();


/**
 * Concilia UMA NFC-e antiga em homologação cujo retorno original da SEFAZ
 * comprova rejeição, mas ficou "pending" por um parser anterior.
 * Sem --apply, apenas confere. Não reenvia e não altera XML/venda/estoque.
 */
Artisan::command('nfce:reconcile-rejection {number : Numero da NFC-e} {--series=1 : Serie fiscal} {--apply : Confirmar ajuste do status}', function () {
    $number=(int) $this->argument('number');
    $series=(int) $this->option('series');
    if ($number < 1 || $series < 0 || $series > 999) {
        $this->error('Numero ou serie invalida.');
        return 1;
    }

    $documents=\App\Models\FiscalDocumentJob::query()
        ->where('document_type','nfce')
        ->where('environment','homologation')
        ->where('series',$series)
        ->where('document_number',$number)
        ->where('status','pending')->get();
    if ($documents->isEmpty()) {
        $this->warn('Nenhuma NFC-e pendente corresponde a essa serie e numero em homologacao.');
        return 0;
    }

    $parser=app(\App\Services\Fiscal\NFCeSefazResponseParser::class);
    $xml=app(\App\Services\Fiscal\NFCeProtocolXmlService::class);
    $disk=\Illuminate\Support\Facades\Storage::disk('local');
    $reconciled=0;
    foreach ($documents as $document) {
        $base='fiscal/nfce/'.$document->id.'/';
        $signedPath=$base.'signed.xml';
        $responsePath=$base.'sefaz-response.ini';

        if ($document->response_path !== $responsePath
            || $document->xml_path !== $signedPath
            || !$disk->exists($responsePath) || !$disk->exists($signedPath)
            || !preg_match('/^\\d{44}$/',(string)$document->access_key)
            || $document->protocol || $document->authorized_at
            || !$document->sale_id
            || $document->emission_mode!=='normal') {
            $this->warn('Documento #'.$document->id.': arquivos ou estado incompatíveis, ignorado.');
            continue;
        }

        $result=$parser->parse($disk->get($responsePath));
        if (!$parser->definitiveCorrectionRejection($result)
            || ($result['key']!==null && !hash_equals((string)$document->access_key,(string)$result['key']))) {
            $this->warn('Documento #'.$document->id.': retorno sem rejeição conclusiva, ignorado.');
            continue;
        }

        try {
            $signedKey=$xml->verifySigned($disk->get($signedPath),$document);
            if (!hash_equals((string)$document->access_key,$signedKey)) {
                throw new \RuntimeException('Chave divergente.');
            }
        } catch (\Throwable) {
            $this->warn('Documento #'.$document->id.': XML assinado não confere, ignorado.');
            continue;
        }

        $blocking=\App\Models\FiscalDocumentJob::query()
            ->where('document_type','nfce')->where('sale_id',$document->sale_id)
            ->where('id','!=',$document->id)
            ->whereIn('status',['authorized','processing','offline_signed','offline_sending'])
            ->exists();
        if ($blocking) {
            $this->warn('Documento #'.$document->id.': há outra nota autorizada/ativa para a venda, ignorado.');
            continue;
        }

        $this->info('NFC-e '.$series.'/'.$number.' (#'.$document->id.'): rejeição '.$result['cstat'].' comprovada.');
        if (!$this->option('apply')) {
            $this->line('Simulação. Repita com --apply para atualizar somente o status.');
            continue;
        }

        // Conferir novamente dentro da transação, antes da mudança de estado.
        $updated=\Illuminate\Support\Facades\DB::transaction(function () use($document) {
            if($document->sale_id) {
                \App\Models\Sale::query()->whereKey($document->sale_id)->lockForUpdate()->firstOrFail();
            }
            $locked=\App\Models\FiscalDocumentJob::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            if($locked->status!=='pending'
                || \App\Models\FiscalDocumentJob::query()
                    ->where('document_type','nfce')->where('sale_id',$locked->sale_id)
                    ->where('id','!=',$locked->id)
                    ->whereIn('status',['authorized','processing','offline_signed','offline_sending'])->exists()) {
                return false;
            }
            $locked->update(['status'=>'rejected']);
            return true;
        });
        if($updated) {
            $this->info('Status reconciliado para Rejeitada; XML e venda preservados.');
            $reconciled++;
        } else {
            $this->warn('Documento #'.$document->id.': estado mudou durante a conciliação.');
        }
    }
    return 0;
})->purpose('Reconcilia rejeição NFC-e em homologação sem retransmitir');
