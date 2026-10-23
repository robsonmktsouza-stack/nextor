<?php

namespace App\Services\Fiscal;

use App\Models\CompanySetting;
use App\Models\IncomingNfe;
use App\Models\IncomingNfeManifestationAttempt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

final class IncomingNfeManifestationService
{
    public const EVENTS=[
        '210210'=>'Ciência da operação',
        '210200'=>'Confirmação da operação',
        '210220'=>'Desconhecimento da operação',
        '210240'=>'Operação não realizada',
    ];

    public function __construct(
        private readonly IncomingNfeSyncService $sync,
        private readonly IncomingNfeManifestationGateway $gateway,
    ) {}

    public function manifest(IncomingNfe $invoice, string $event, ?string $reason, ?int $userId): array
    {
        if (!isset(self::EVENTS[$event])) {
            throw ValidationException::withMessages(['event_type'=>'Manifestação inválida.']);
        }
        $reason=$event==='210240' ? trim((string)$reason) : null;
        if ($event==='210240' && (mb_strlen($reason)<15 || mb_strlen($reason)>255
            || preg_match('/[\r\n]/',$reason))) {
            throw ValidationException::withMessages(['reason'=>'Informe uma justificativa de 15 a 255 caracteres, em uma linha.']);
        }
        if (!$this->sync->isReady()) return ['status'=>'not_ready'];
        $cnpj=$this->sync->companyCnpj();
        if (!preg_match('/^\d{44}$/',$invoice->access_key) || substr($invoice->access_key,20,2)!=='55') {
            throw ValidationException::withMessages(['event_type'=>'Documento não é uma NF-e modelo 55.']);
        }
        $lock=Cache::lock('incoming-manifest:'.$cnpj.':'.$invoice->access_key,180);
        if (!$lock->get()) return ['status'=>'busy'];
        try {
            $attempt=DB::transaction(function () use ($invoice,$event,$reason,$userId) {
                $locked=IncomingNfe::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
                if ($locked->manifestation===$event) return 'already';
                // An interrupted/uncertain transmission must never be retried blindly.
                if ($locked->manifestationAttempts()->whereIn('status',['pending','uncertain'])->exists()) {
                    return 'review';
                }
                return $locked->manifestationAttempts()->create([
                    'event_code'=>$event,'status'=>'pending','reason'=>$reason,'user_id'=>$userId,
                ]);
            },3);
            if (is_string($attempt)) return ['status'=>$attempt];
            $code=null;
            $protocol=null;
            $resultStatus='uncertain';
            $responseMessage='Retorno não confirmado. Confira o protocolo na SEFAZ antes de reenviar.';
            try {
                $raw=$this->gateway->send(CompanySetting::current(),$cnpj,
                    $invoice->access_key,$event,$reason);
                $parsed=$this->parseResponse($raw,$invoice->access_key,$event);
                $code=$parsed['code'];
                $protocol=$parsed['protocol'];
                $resultStatus=$parsed['status'];
                $responseMessage=$parsed['message'];
            } catch (Throwable $error) {
                // Never expose ACBr raw diagnostics: they may contain private certificate data.
                Log::warning('Manifestação NF-e: retorno inconclusivo',[
                    'kind'=>get_class($error),'invoice_id'=>$invoice->id,
                ]);
            }
            DB::transaction(function () use ($attempt,$invoice,$event,$resultStatus,$code,$protocol,$responseMessage): void {
                $pending=IncomingNfeManifestationAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
                $pending->update([
                    'status'=>$resultStatus,'sefaz_status'=>$code,'protocol'=>$protocol,
                    'response_message'=>$responseMessage,
                ]);
                if ($resultStatus==='accepted') {
                    IncomingNfe::query()->whereKey($invoice->id)->update([
                        'manifestation'=>$event,'manifestation_protocol'=>$protocol,'manifested_at'=>now(),
                    ]);
                }
            },3);
            return ['status'=>$resultStatus,'code'=>$code];
        } finally {
            $lock->release();
        }
    }

    /** Somente cStat individual 135, chave, tipo, ambiente e protocolo coerentes confirmam o envio. */
    public function parseResponse(string $raw,string $key,string $event): array
    {
        $section='';
        $fields=[];
        foreach (preg_split('/\r\n|\r|\n/',$raw) as $line) {
            $line=trim($line);
            if (preg_match('/^\[([^\]]+)\]$/',$line,$match)) {
                $section=strtolower($match[1]);
                continue;
            }
            if ($section!=='evento001' || !str_contains($line,'=')) continue;
            [$name,$value]=explode('=',$line,2);
            $fields[strtolower(trim($name))]=trim($value);
        }
        $code=(string)($fields['cstat']??'');
        if (!preg_match('/^\d{3}$/',$code)) {
            return ['status'=>'uncertain','code'=>null,'protocol'=>null,
                'message'=>'Sem retorno individual verificável da SEFAZ.'];
        }
        $protocol=trim((string)($fields['nprot']??''));
        $matches=hash_equals($key,(string)($fields['chdfe']??$fields['chnfe']??''))
            && hash_equals($event,(string)($fields['tpevento']??''))
            && ($fields['tpamb']??'')==='1'
            && (!isset($fields['nseqevento']) || $fields['nseqevento']==='1');
        if ($code==='135' && $matches && preg_match('/^\d{15}$/',$protocol)) {
            return ['status'=>'accepted','code'=>$code,'protocol'=>$protocol,
                'message'=>'Manifestação registrada na SEFAZ.'];
        }
        // 573=duplicate; 136=registered but not linked. Neither proves this request.
        if (!in_array($code,['135','136','573'],true)) {
            return ['status'=>'rejected','code'=>$code,'protocol'=>null,
                'message'=>'SEFAZ rejeitou o evento (cStat '.$code.').'];
        }
        return ['status'=>'uncertain','code'=>$code,'protocol'=>null,
            'message'=>'Evento sem confirmação individual válida. Confira na SEFAZ.'];
    }
}
