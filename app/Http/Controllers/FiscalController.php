<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\FiscalDocumentJob;
use Carbon\Carbon;
use Illuminate\Http\Request;

class FiscalController extends Controller
{
    public const TABS=[
        'nfe'=>['label'=>'NF-e','description'=>'Nota Fiscal Eletrônica — modelo 55','party'=>'Destinatário'],
        'nfse'=>['label'=>'NFS-e','description'=>'Nota Fiscal de Serviço Eletrônica','party'=>'Tomador'],
        'cte'=>['label'=>'CT-e','description'=>'Conhecimento de Transporte Eletrônico — modelo 57','party'=>'Tomador'],
        'mdfe'=>['label'=>'MDF-e','description'=>'Manifesto Eletrônico de Documentos Fiscais — modelo 58','party'=>'Emitente'],
        'nfce'=>['label'=>'NFC-e','description'=>'Nota Fiscal de Consumidor Eletrônica — modelo 65','party'=>'Consumidor'],
    ];

    public function index(Request $request)
    {
        $tab=(string)$request->query('tab','nfe');
        if(!array_key_exists($tab,self::TABS)) $tab='nfe';

        $term=trim((string)$request->query('search',''));
        $status=trim((string)$request->query('status',''));
        $environment=trim((string)$request->query('environment',''));
        $month=(string)$request->query('month',now()->format('Y-m'));
        if(!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',$month)) {
            $month=now()->format('Y-m');
        }

        $period=Carbon::createFromFormat('Y-m-d',$month.'-01')->startOfMonth();
        $periodEnd=$period->copy()->endOfMonth();

        $query=FiscalDocumentJob::query()
            ->with(['sale.customer'])
            ->where('document_type',$tab);

        if($term!=='') {
            $query->where(function($q) use($term) {
                $like='%'.$term.'%';

                $q->where('access_key','like',$like)
                    ->orWhere('protocol','like',$like)
                    ->orWhere('error_message','like',$like)
                    ->orWhereHas('sale',function($sale) use($like,$term) {
                        $sale->where('keyword','like',$like)
                            ->orWhereHas('customer',fn($customer)=>$customer->where('name','like',$like));

                        if(ctype_digit($term)) {
                            $sale->orWhere('id',(int)$term);
                        }
                    });

                if(ctype_digit($term)) {
                    $q->orWhere('document_number',(int)$term)
                        ->orWhere('sale_id',(int)$term);
                }
            });
        }

        $query->where(function($dateQuery) use($period,$periodEnd) {
            $dateQuery->whereBetween('prepared_at',[$period,$periodEnd])
                ->orWhere(function($fallback) use($period,$periodEnd) {
                    $fallback->whereNull('prepared_at')
                        ->whereBetween('created_at',[$period,$periodEnd]);
                });
        });

        if($status!=='') $query->where('status',$status);
        if(in_array($environment,['homologation','production'],true)) {
            $query->where('environment',$environment);
        } else {
            $environment='';
        }

        $documents=$query
            ->latest('prepared_at')
            ->latest('id')
            ->paginate(AppSetting::tablePerPage($request))
            ->withQueryString();

        $monthNames=[
            1=>'Janeiro',2=>'Fevereiro',3=>'Março',4=>'Abril',5=>'Maio',6=>'Junho',
            7=>'Julho',8=>'Agosto',9=>'Setembro',10=>'Outubro',11=>'Novembro',12=>'Dezembro',
        ];

        return view('fiscal.index',[
            'tab'=>$tab,
            'tabs'=>self::TABS,
            'tabMeta'=>self::TABS[$tab],
            'documents'=>$documents,
            'term'=>$term,
            'status'=>$status,
            'environment'=>$environment,
            'month'=>$month,
            'prevMonth'=>$period->copy()->subMonth()->format('Y-m'),
            'nextMonth'=>$period->copy()->addMonth()->format('Y-m'),
            'monthLabel'=>$monthNames[(int)$period->format('n')].' '.$period->format('Y'),
            'configuration'=>$this->configurationFor($tab),
            'dateFormat'=>AppSetting::dateFormat(),
        ]);
    }

    public function show(FiscalDocumentJob $fiscalDocumentJob)
    {
        $fiscalDocumentJob->load(['sale.customer','sale.user','sale.payments']);

        $tab=array_key_exists($fiscalDocumentJob->document_type,self::TABS)
            ? $fiscalDocumentJob->document_type
            : 'nfe';

        // NFC-e emitidas/canceladas são abertas no próprio formulário de emissão,
        // com os dados originais congelados e controles de edição desabilitados.
        if ($tab==='nfce' && in_array($fiscalDocumentJob->status,['authorized','cancelled'],true)) {
            return view('fiscal.nfce.create',
                app(\App\Services\Fiscal\NFCeReadonlyViewData::class)->forDocument($fiscalDocumentJob)
            );
        }

        return view('fiscal.show',[
            'document'=>$fiscalDocumentJob,
            'tab'=>$tab,
            'tabs'=>self::TABS,
            'tabMeta'=>self::TABS[$tab],
            'configuration'=>$this->configurationFor($tab),
            'fiscalEnabled'=>(bool)AppSetting::value('fiscal','enabled',false),
            'dateFormat'=>AppSetting::dateFormat(),
            'nfcePreflightErrors'=>$fiscalDocumentJob->document_type==='nfce'
                && $fiscalDocumentJob->status==='prepared'
                    ? app(\App\Services\Fiscal\NFCePreflightService::class)->validate($fiscalDocumentJob)
                    : [],
        ]);
    }

    public function refreshNfceFiscalData(
        FiscalDocumentJob $fiscalDocumentJob,
        \App\Services\Fiscal\NFCeFiscalDataRefreshService $refresh
    ) {
        abort_unless($fiscalDocumentJob->document_type === 'nfce', 404);

        try {
            $refresh->refresh($fiscalDocumentJob);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return redirect()->route('fiscal.show', $fiscalDocumentJob)
                ->with('error', collect($e->errors())->flatten()->implode(' '));
        }

        $error = (string) $fiscalDocumentJob->fresh()?->error_message;
        if ($error !== '') {
            return redirect()->route('fiscal.show', $fiscalDocumentJob)
                ->with('error', $error);
        }

        return redirect()->route('fiscal.show', $fiscalDocumentJob)
            ->with('success', 'Dados fiscais atualizados conforme as configurações da empresa.');
    }

    public function issueNfce(FiscalDocumentJob $fiscalDocumentJob, \App\Services\Fiscal\NFCePreflightService $preflight)
    {
        abort_unless($fiscalDocumentJob->document_type === 'nfce', 404);

        $queueConnection=(string)config('queue.default','sync');
        if (in_array($queueConnection,['sync','null',''],true)) {
            return back()->with('error','Configure o processamento em segundo plano antes de emitir.');
        }

        $problems = $preflight->validate($fiscalDocumentJob);
        if ($problems) {
            return back()->with('error', implode(' | ', $problems));
        }

        \App\Jobs\ProcessNFCeJob::dispatch($fiscalDocumentJob->id)->onConnection($queueConnection);

        return back()->with('success', $fiscalDocumentJob->emission_mode==='offline'
            ? 'Preparação offline colocada na fila: assinatura e DANFE, sem envio à SEFAZ.'
            : 'Emissão colocada na fila fiscal. Acompanhe o resultado neste documento.');
    }

    public function consultNfce(FiscalDocumentJob $fiscalDocumentJob)
    {
        abort_unless($fiscalDocumentJob->document_type === 'nfce', 404);
        if ($fiscalDocumentJob->status !== 'pending'
            || preg_match('/^\d{44}$/', (string) $fiscalDocumentJob->access_key) !== 1) {
            return back()->with('error', 'Apenas NFC-e pendente com chave de acesso pode ser consultada.');
        }
        $queueConnection=(string)config('queue.default','sync');
        if (in_array($queueConnection,['sync','null',''],true)) {
            return back()->with('error','Configure o processamento em segundo plano para consultar a nota.');
        }
        \App\Jobs\ConsultNFCeJob::dispatch($fiscalDocumentJob->id)->onConnection($queueConnection);

        return back()->with('success', 'Consulta da NFC-e enviada à fila. A nota não será retransmitida.');
    }

    /**
     * Reprocessa SOMENTE o arquivo autorizado com os registros originais.
     * Nunca envia novamente a NFC-e à SEFAZ.
     */
    public function recoverNfceXml(
        FiscalDocumentJob $fiscalDocumentJob,
        \App\Services\Fiscal\NFCeAuthorizedXmlRecoveryService $recovery
    ) {
        abort_unless($fiscalDocumentJob->document_type === 'nfce', 404);
        if ($fiscalDocumentJob->status !== 'authorized') {
            return back()->with('error', 'Somente NFC-e autorizada pode ter o XML reconstruído.');
        }

        try {
            $recovery->recover($fiscalDocumentJob);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Falha na reconstrução do XML autorizado da NFC-e', [
                'fiscal_document_job_id' => $fiscalDocumentJob->id,
                'reason' => $e->getMessage(),
            ]);
            return back()->with('error', 'Não foi possível recuperar o XML. Consulte o log do Lumeron. Nenhuma nota foi retransmitida.');
        }

        return redirect()->route('fiscal.show', $fiscalDocumentJob)
            ->with('success', 'XML autorizado recuperado a partir dos arquivos originais. A NFC-e não foi retransmitida.');
    }

    public function downloadNfceXml(FiscalDocumentJob $fiscalDocumentJob)
    {
        abort_unless($fiscalDocumentJob->document_type === 'nfce', 404);
        $path = (string) $fiscalDocumentJob->xml_path;
        $allowed = [
            'fiscal/nfce/'.$fiscalDocumentJob->id.'/signed.xml',
            'fiscal/nfce/'.$fiscalDocumentJob->id.'/authorized.xml',
            'fiscal/nfce/'.$fiscalDocumentJob->id.'/authorized-recovered.xml',
        ];
        abort_unless(in_array($path, $allowed, true)
            && \Illuminate\Support\Facades\Storage::disk('local')->exists($path), 404);

        $suffix = str_contains($path, '/authorized-') || str_ends_with($path, '/authorized.xml') ? 'autorizada' : 'assinada';
        $filename = 'NFCe-'.$fiscalDocumentJob->document_number.'-'.$suffix.'.xml';
        return \Illuminate\Support\Facades\Storage::disk('local')
            ->download($path, $filename, ['Content-Type' => 'application/xml']);
    }

    private function configurationFor(string $tab): array
    {
        $settingsService=app(\App\Services\Fiscal\FiscalDocumentSettings::class);
        $group=in_array($tab,['cte','mdfe'],true) ? 'cte' : $tab;
        $settings=AppSetting::groupValues($group,[]);

        $seriesKey=in_array($tab,['cte','mdfe'],true) ? $tab.'_series' : 'series';
        $numberKey=match($tab) {
            'nfse'=>'next_rps',
            'cte','mdfe'=>$tab.'_next_number',
            default=>'next_number',
        };

        return [
            'enabled'=>$settingsService->enabled($tab),
            'environment'=>$settingsService->environment($tab),
            'series'=>$settings[$seriesKey] ?? null,
            'next_number'=>$settings[$numberKey] ?? 1,
            'settings_tab'=>$group,
        ];
    }
}
