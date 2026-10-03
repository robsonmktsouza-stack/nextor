<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\FiscalDocumentJob;
use Illuminate\Http\Request;

class FiscalController extends Controller
{
    private const TABS=[
        'nfe'=>['label'=>'NF-e','description'=>'Nota Fiscal Eletrônica — modelo 55'],
        'nfse'=>['label'=>'NFS-e','description'=>'Nota Fiscal de Serviço Eletrônica'],
        'cte'=>['label'=>'CT-e','description'=>'Conhecimento de Transporte Eletrônico — modelo 57'],
        'nfce'=>['label'=>'NFC-e','description'=>'Nota Fiscal de Consumidor Eletrônica — modelo 65'],
    ];

    public function index(Request $request)
    {
        $tab=(string)$request->query('tab','nfe');
        if(!array_key_exists($tab,self::TABS)) $tab='nfe';

        $term=trim((string)$request->query('search',''));
        $status=trim((string)$request->query('status',''));
        $environment=trim((string)$request->query('environment',''));

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

        if($status!=='') $query->where('status',$status);
        if(in_array($environment,['homologation','production'],true)) {
            $query->where('environment',$environment);
        } else {
            $environment='';
        }

        $documents=$query
            ->latest('id')
            ->paginate(AppSetting::tablePerPage($request))
            ->withQueryString();

        $tabCounts=FiscalDocumentJob::query()
            ->selectRaw('document_type, COUNT(*) as total')
            ->whereIn('document_type',array_keys(self::TABS))
            ->groupBy('document_type')
            ->pluck('total','document_type')
            ->map(fn($value)=>(int)$value)
            ->all();

        $statusCounts=FiscalDocumentJob::query()
            ->where('document_type',$tab)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total','status')
            ->map(fn($value)=>(int)$value)
            ->all();

        return view('fiscal.index',[
            'tab'=>$tab,
            'tabs'=>self::TABS,
            'tabMeta'=>self::TABS[$tab],
            'tabCounts'=>$tabCounts,
            'statusCounts'=>$statusCounts,
            'documents'=>$documents,
            'term'=>$term,
            'status'=>$status,
            'environment'=>$environment,
            'configuration'=>$this->configurationFor($tab),
            'fiscalEnabled'=>(bool)AppSetting::value('fiscal','enabled',false),
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
            'tabCounts'=>FiscalDocumentJob::query()
                ->selectRaw('document_type, COUNT(*) as total')
                ->whereIn('document_type',array_keys(self::TABS))
                ->groupBy('document_type')
                ->pluck('total','document_type')
                ->map(fn($value)=>(int)$value)
                ->all(),
            'configuration'=>$this->configurationFor($tab),
            'fiscalEnabled'=>(bool)AppSetting::value('fiscal','enabled',false),
        ]);
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
