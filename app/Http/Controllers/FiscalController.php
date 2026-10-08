<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\FiscalDocumentJob;
use Carbon\Carbon;
use Illuminate\Http\Request;

class FiscalController extends Controller
{
    private const TABS=[
        'nfe'=>['label'=>'NF-e','description'=>'Nota Fiscal Eletrônica — modelo 55','party'=>'Destinatário'],
        'nfse'=>['label'=>'NFS-e','description'=>'Nota Fiscal de Serviço Eletrônica','party'=>'Tomador'],
        'cte'=>['label'=>'CT-e','description'=>'Conhecimento de Transporte Eletrônico — modelo 57','party'=>'Tomador'],
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
                    ? app(\\App\\Services\\Fiscal\\NFCePreflightService::class)->validate($fiscalDocumentJob)
                    : [],
        ]);
    }

    public function issueNfce(FiscalDocumentJob $fiscalDocumentJob, \App\Services\Fiscal\NFCePreflightService $preflight)
    {
        abort_unless($fiscalDocumentJob->document_type === 'nfce', 404);

        if (config('queue.default') !== 'database') {
            return back()->with('error', 'Configure QUEUE_CONNECTION=database e inicie o worker fiscal antes de emitir.');
        }

        $problems = $preflight->validate($fiscalDocumentJob);
        if ($problems) {
            return back()->with('error', implode(' | ', $problems));
        }

        \App\Jobs\ProcessNFCeJob::dispatch($fiscalDocumentJob->id)->onConnection('database');

        return back()->with('success', 'Emissão colocada na fila fiscal. Acompanhe o resultado neste documento.');
    }

    private function configurationFor(string $tab): array
    {
        if($tab==='cte') {
            $settings=AppSetting::groupValues('cte',[]);

            return [
                'enabled'=>(bool)($settings['cte_enabled'] ?? false),
                'environment'=>$settings['cte_environment'] ?? 'homologation',
                'series'=>$settings['cte_series'] ?? 1,
                'next_number'=>$settings['cte_next_number'] ?? 1,
                'settings_tab'=>'cte',
            ];
        }

        $settings=AppSetting::groupValues($tab,[]);

        return [
            'enabled'=>(bool)($settings['enabled'] ?? false),
            'environment'=>$settings['environment'] ?? AppSetting::value('fiscal','default_environment','homologation'),
            'series'=>$settings['series'] ?? null,
            'next_number'=>$tab==='nfse' ? ($settings['next_rps'] ?? 1) : ($settings['next_number'] ?? 1),
            'settings_tab'=>$tab,
        ];
    }
}
