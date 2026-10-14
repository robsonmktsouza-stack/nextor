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

        // Mostrar uma NFC-e por venda: a autorizada prevalece, caso
        // exista; senão, a tentativa mais recente. Não apagar tentativas
        // antigas: elas continuam acessíveis no histórico do editor.
        // A seleção acontece ANTES dos filtros, evitando que uma tentativa
        // rejeitada reapareça quando a venda já possui nota autorizada.
        if ($tab==='nfce') {
            $query->where(function($visible) {
                $visible->whereNull('fiscal_document_jobs.sale_id')
                    ->orWhereNotExists(function($others) {
                        $others->selectRaw('1')
                            ->from('fiscal_document_jobs as other_nfce')
                            ->where('other_nfce.document_type','nfce')
                            ->whereColumn('other_nfce.sale_id','fiscal_document_jobs.sale_id')
                            ->where(function($higher) {
                                $higher->where(function($authorized) {
                                    $authorized->where('other_nfce.status','authorized')
                                        ->where('fiscal_document_jobs.status','!=','authorized');
                                })->orWhere(function($later) {
                                    $later->whereColumn('other_nfce.id','>','fiscal_document_jobs.id')
                                        ->where(function($priority) {
                                            $priority->where('fiscal_document_jobs.status','!=','authorized')
                                                ->orWhere('other_nfce.status','authorized');
                                        });
                                });
                            });
                    });
            });
        }

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

        // Toda NFC-e abre diretamente no mesmo editor de documentos.
        // Somente uma rejeição individual e comprovada permite corrigir os
        // dados fiscais: consulta inconclusiva (613), nota autorizada e
        // tentativa substituída não podem ser reenviadas/editadas.
        if ($tab==='nfce') {
            $authorizedSibling = $fiscalDocumentJob->sale_id
                && $fiscalDocumentJob->status !== 'authorized'
                ? FiscalDocumentJob::query()
                    ->where('document_type','nfce')
                    ->where('sale_id',$fiscalDocumentJob->sale_id)
                    ->where('id','!=',$fiscalDocumentJob->id)
                    ->where('status','authorized')
                    ->first()
                : null;

            if (!$authorizedSibling && app(\App\Services\Fiscal\NFCeRejectionCorrectionService::class)
                ->rejection($fiscalDocumentJob) !== null) {
                return $this->editRejectedNfce(
                    $fiscalDocumentJob,
                    app(\App\Services\Fiscal\NFCeRejectionCorrectionService::class)
                );
            }

            $data = app(\App\Services\Fiscal\NFCeReadonlyViewData::class)->forDocument($fiscalDocumentJob);
            $data['nfceAuthorizedSibling'] = $authorizedSibling;
            $data['nfceViewStatus'] = $authorizedSibling
                ? 'Substituída'
                : match($fiscalDocumentJob->status) {
                    'authorized'=>'Autorizada', 'cancelled'=>'Cancelada',
                    'rejected'=>'Rejeitada', 'pending'=>'Pendente de confirmação',
                    'prepared'=>'Preparada', 'processing'=>'Processando',
                    'offline_signed'=>'Contingência assinada',
                    'offline_print_pending'=>'DANFE pendente',
                    'offline_sending'=>'Transmitindo contingência',
                    'failed'=>'Falha', 'error'=>'Erro',
                    default=>'A verificar',
                };
            $data['nfcePreflightErrors'] = $fiscalDocumentJob->status === 'prepared'
                ? app(\App\Services\Fiscal\NFCePreflightService::class)->validate($fiscalDocumentJob)
                : [];
            $data['nfceDuplicateAvailable'] = app(\App\Services\Fiscal\NFCeDuplicationService::class)
                ->eligible($fiscalDocumentJob);
            $data['nfceCorrectedNext'] = FiscalDocumentJob::query()
                ->where('corrected_from_id',$fiscalDocumentJob->id)->first();
            $data['nfceDuplicateNext'] = FiscalDocumentJob::query()
                ->where('duplicated_from_id',$fiscalDocumentJob->id)->first();
            $data['nfceDuplicateOriginal'] = $fiscalDocumentJob->duplicated_from_id
                ? FiscalDocumentJob::query()->find($fiscalDocumentJob->duplicated_from_id)
                : null;
            $data['nfceHistory'] = $this->nfceHistory($fiscalDocumentJob);
            return view('fiscal.nfce.create', $data);
        }

        return view('fiscal.show',[
            'document'=>$fiscalDocumentJob,
            'tab'=>$tab,
            'tabs'=>self::TABS,
            'tabMeta'=>self::TABS[$tab],
            'configuration'=>$this->configurationFor($tab),
            'fiscalEnabled'=>(bool)AppSetting::value('fiscal','enabled',false),
            'dateFormat'=>AppSetting::dateFormat(),
            'canCorrectNfce'=>$fiscalDocumentJob->document_type==='nfce'
                && app(\App\Services\Fiscal\NFCeRejectionCorrectionService::class)
                    ->rejection($fiscalDocumentJob) !== null,
            'correctionRevision'=>\Illuminate\Support\Facades\DB::table('nfce_correction_revisions')
                ->where('corrected_document_id',$fiscalDocumentJob->id)->first(),
            'correctedFromNfce'=>$fiscalDocumentJob->corrected_from_id
                ? FiscalDocumentJob::query()->find($fiscalDocumentJob->corrected_from_id)
                : null,
            'correctedNextNfce'=>FiscalDocumentJob::query()
                ->where('corrected_from_id',$fiscalDocumentJob->id)->first(),
            'originalNfce'=>$fiscalDocumentJob->duplicated_from_id
                ? FiscalDocumentJob::query()->find($fiscalDocumentJob->duplicated_from_id)
                : null,
            'duplicateNfce'=>FiscalDocumentJob::query()
                ->where('duplicated_from_id', $fiscalDocumentJob->id)->first(),
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
            ->with('success', 'Dados fiscais atualizados.');
    }

    public function issueNfce(FiscalDocumentJob $fiscalDocumentJob, \App\Services\Fiscal\NFCePreflightService $preflight)
    {
        abort_unless($fiscalDocumentJob->document_type === 'nfce', 404);

        $queueConnection=(string)config('queue.default','sync');
        if (in_array($queueConnection,['sync','null',''],true)) {
            return back()->with('error','Não foi possível iniciar a emissão. Verifique a configuração fiscal.');
        }

        $problems = $preflight->validate($fiscalDocumentJob);
        if ($problems) {
            return back()->with('error', implode(' | ', $problems));
        }

        \App\Jobs\ProcessNFCeJob::dispatch($fiscalDocumentJob->id)->onConnection($queueConnection);

        return back()->with('success', $fiscalDocumentJob->emission_mode==='offline'
            ? 'Preparando a NFC-e em contingência.'
            : 'Emissão iniciada. Acompanhe a situação da nota.');
    }


    /**
     * Create a separate fiscal document after definitive 539 rejection.
     * Original signed XML remains unchanged; no sale/stock/finance writes.
     */
    public function duplicateNfce(
        FiscalDocumentJob $fiscalDocumentJob,
        \App\Services\Fiscal\NFCeDuplicationService $duplicator
    ) {
        abort_unless($fiscalDocumentJob->document_type === 'nfce', 404);

        try {
            $copy = $duplicator->duplicate($fiscalDocumentJob->id);
        } catch (\Illuminate\Validation\ValidationException $exception) {
            return back()->with('error', collect($exception->errors())->flatten()->implode(' '));
        }

        return redirect()->route('fiscal.show', $copy)
            ->with('success', 'Nova NFC-e preparada. Confira antes de emitir.');
    }

    public function editRejectedNfce(
        FiscalDocumentJob $fiscalDocumentJob,
        \App\Services\Fiscal\NFCeRejectionCorrectionService $correction
    ) {
        abort_unless($fiscalDocumentJob->document_type === 'nfce', 404);
        $rejection = $correction->rejection($fiscalDocumentJob);
        if (!$rejection) {
            return redirect()->route('fiscal.show', $fiscalDocumentJob)
                ->with('error', 'Esta NFC-e ainda não pode ser corrigida. Consulte a situação da nota.');
        }

        $paymentMethods = \App\Models\PaymentMethod::query()
            ->where('is_active', true)
            ->whereIn('kind', ['cash','money','pix','card','credit_card','debit_card',
                'bank_slip','bank_transfer','transfer'])
            ->orderBy('sort_order')->orderBy('name')->get(['code','name','kind']);

        return view('fiscal.nfce.create', [
            'correctionDocument'=>$fiscalDocumentJob,
            'document'=>$fiscalDocumentJob,
            'rejection'=>$rejection,
            'company'=>\App\Models\CompanySetting::current(),
            'snapshot'=>$fiscalDocumentJob->source_snapshot ?? [],
            'paymentMethods'=>$paymentMethods,
            'nfceHistory'=>$this->nfceHistory($fiscalDocumentJob),
        ]);
    }

    public function saveRejectedNfce(
        \Illuminate\Http\Request $request,
        FiscalDocumentJob $fiscalDocumentJob,
        \App\Services\Fiscal\NFCeRejectionCorrectionService $correction
    ) {
        abort_unless($fiscalDocumentJob->document_type === 'nfce', 404);
        try {
            $copy = $correction->prepare($fiscalDocumentJob->id, $request->all(), (int)$request->user()->id);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withInput()->withErrors($e->errors());
        }

        // A pessoa usa um unico comando "Salvar e transmitir". A revisao
        // e salva uma unica vez e a transmissao permanece protegida pelo
        // preflight e pela trava da venda no processador fiscal.
        if ($copy->status !== 'prepared') {
            return redirect()->route('fiscal.show',$copy)
                ->with('success','Correção já salva. Abra a nota atual.');
        }

        $problems=app(\App\Services\Fiscal\NFCePreflightService::class)->validate($copy);
        if ($problems) {
            return redirect()->route('fiscal.show',$copy)
                ->with('error','Correção salva. Verifique as pendências: '.implode(' | ',$problems));
        }
        $queue=(string) config('queue.default','sync');
        if (in_array($queue,['','sync','null'],true)) {
            return redirect()->route('fiscal.show',$copy)
                ->with('error','Correção salva, mas não foi possível iniciar a emissão. Verifique a configuração fiscal.');
        }

        try {
            \App\Jobs\ProcessNFCeJob::dispatch($copy->id)->onConnection($queue);
        } catch (\Throwable $error) {
            \Illuminate\Support\Facades\Log::error('Erro ao enfileirar NFC-e corrigida',[
                'fiscal_document_job_id'=>$copy->id,
                'message'=>$error->getMessage(),
            ]);
            return redirect()->route('fiscal.show',$copy)
                ->with('error','Correção salva, mas não foi possível iniciar a emissão.');
        }

        return redirect()->route('fiscal.show',$copy)
            ->with('success','Correção salva. Emissão iniciada.');
    }

    private function nfceHistory(FiscalDocumentJob $document)
    {
        if (!$document->sale_id) {
            return collect([$document]);
        }
        return FiscalDocumentJob::query()
            ->where('document_type','nfce')
            ->where('sale_id',$document->sale_id)
            ->orderByDesc('id')
            ->get();
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
            return back()->with('error','Não foi possível iniciar a consulta. Tente novamente.');
        }
        \App\Jobs\ConsultNFCeJob::dispatch($fiscalDocumentJob->id)->onConnection($queueConnection);

        return back()->with('success', 'Consulta iniciada. Aguarde o resultado.');
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
            return back()->with('error', 'Não foi possível recuperar o XML. Tente novamente ou contate o suporte.');
        }

        return redirect()->route('fiscal.show', $fiscalDocumentJob)
            ->with('success', 'XML recuperado com sucesso.');
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
