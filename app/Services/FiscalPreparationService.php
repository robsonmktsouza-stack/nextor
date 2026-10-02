<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\FiscalDocumentJob;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;

class FiscalPreparationService
{
    public function prepareForSale(Sale $sale): void
    {
        if($sale->operation_type!=='sale' || $sale->status!=='completed') return;
        if(!(bool)AppSetting::value('fiscal','enabled',false)) return;

        $sale->loadMissing(['items','customer']);

        if($sale->source==='pdv') {
            $pdvAuto=(bool)AppSetting::value('pdv','auto_nfce',false);
            $nfceAuto=(bool)AppSetting::value('nfce','auto_from_pdv',false);

            if(($pdvAuto || $nfceAuto) && (bool)AppSetting::value('nfce','enabled',false)) {
                $this->prepare($sale,'nfce','nfce','next_number');
            }

            return;
        }

        $hasProducts=$sale->items->contains(fn($item)=>$item->item_type==='product');
        $hasServices=$sale->items->contains(fn($item)=>$item->item_type==='service');

        if($hasProducts
            && (bool)AppSetting::value('nfe','enabled',false)
            && (bool)AppSetting::value('nfe','auto_from_sale',false)
        ) {
            $this->prepare($sale,'nfe','nfe','next_number');
        }

        if($hasServices
            && (bool)AppSetting::value('nfse','enabled',false)
            && (bool)AppSetting::value('nfse','auto_from_sale',false)
        ) {
            $this->prepare($sale,'nfse','nfse','next_rps');
        }
    }

    private function prepare(Sale $sale,string $documentType,string $group,string $sequenceKey): void
    {
        DB::transaction(function() use($sale,$documentType,$group,$sequenceKey) {
            if(FiscalDocumentJob::query()
                ->where('document_type',$documentType)
                ->where('sale_id',$sale->id)
                ->exists()
            ) {
                return;
            }

            $settings=AppSetting::groupValues($group,[]);
            $number=AppSetting::reserveInteger($group,$sequenceKey,1);
            $environment=(string)($settings['environment']
                ?? AppSetting::value('fiscal','default_environment','homologation'));
            $series=isset($settings['series']) ? (int)$settings['series'] : null;

            $safeSettings=$settings;
            foreach(['csc_token','municipal_password','api_key','api_secret'] as $secretKey) {
                unset($safeSettings[$secretKey]);
            }

            FiscalDocumentJob::query()->create([
                'document_type'=>$documentType,
                'sale_id'=>$sale->id,
                'status'=>'prepared',
                'environment'=>$environment,
                'series'=>$series,
                'document_number'=>$number,
                'settings_snapshot'=>$safeSettings,
                'source_snapshot'=>[
                    'sale_id'=>$sale->id,
                    'source'=>$sale->source,
                    'operation_date'=>optional($sale->operation_date)->toDateString(),
                    'customer_id'=>$sale->customer_id,
                    'total'=>(string)$sale->total,
                    'items'=>$sale->items->map(fn($item)=>[
                        'item_type'=>$item->item_type,
                        'product_id'=>$item->product_id,
                        'service_id'=>$item->service_id,
                        'name'=>$item->product_name,
                        'quantity'=>(string)$item->quantity,
                        'unit_price'=>(string)$item->unit_price,
                        'line_total'=>(string)$item->line_total,
                    ])->values()->all(),
                ],
                'prepared_at'=>now(),
            ]);
        },3);
    }
}
