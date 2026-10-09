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

        $sale->loadMissing(['items.product','items.service','customer','payments']);

        if($sale->source==='pdv') {
            $productOnly=$sale->items->isNotEmpty()
                && $sale->items->every(fn($item)=>$item->item_type==='product' && $item->product_id);

            if(!$productOnly) {
                return;
            }

            // A NFC-e é criada a partir da venda do PDV independentemente
            // da transmissão automática. Isso permite revisão e emissão
            // manual pelo mesmo fluxo fiscal, sem duplicar venda/numeração.
            if ((bool) AppSetting::value('nfce', 'enabled', false)) {
                $this->prepare($sale, 'nfce', 'nfce', 'next_number');
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

            $offline=$documentType==='nfce'
                && (bool)AppSetting::value('nfce','offline_contingency_active',false);
            $contingencyReason=$offline
                ? trim((string)AppSetting::value('nfce','offline_contingency_reason',''))
                : null;
            $contingencyStartedAt=null;

            if($offline) {
                $started=(string)AppSetting::value('nfce','offline_contingency_started_at','');
                try {
                    $contingencyStartedAt=$started!=='' ? \Carbon\Carbon::parse($started) : now();
                } catch (\Throwable) {
                    $contingencyStartedAt=now();
                }
            }

            $safeSettings=$settings;
            foreach(['csc_token','municipal_password','api_key','api_secret'] as $secretKey) {
                unset($safeSettings[$secretKey]);
            }

            $job = FiscalDocumentJob::query()->create([
                'document_type'=>$documentType,
                'sale_id'=>$sale->id,
                'status'=>'prepared',
                'emission_mode'=>$offline ? 'offline' : 'normal',
                'contingency_reason'=>$contingencyReason ?: null,
                'contingency_started_at'=>$contingencyStartedAt,
                'environment'=>$environment,
                'series'=>$series,
                'document_number'=>$number,
                'settings_snapshot'=>$safeSettings,
                'source_snapshot'=>[
                    'sale_id'=>$sale->id,
                    'source'=>$sale->source,
                    'operation_date'=>optional($sale->operation_date)->toDateString(),
                    'customer_id'=>$sale->customer_id,
                    'consumer_document'=>$sale->consumer_document,
                    'consumer_name'=>$sale->consumer_name,
                    'cash_received'=>$sale->cash_received!==null ? (string)$sale->cash_received : null,
                    'change_amount'=>(string)($sale->change_amount ?? 0),
                    'total'=>(string)$sale->total,
                    'items'=>$sale->items->map(fn($item)=>[
                        'item_type'=>$item->item_type,
                        'product_id'=>$item->product_id,
                        'service_id'=>$item->service_id,
                        'name'=>$item->product_name,
                        'sku'=>$item->product_sku,
                        'unit'=>$item->product?->unit,
                        'discount'=>(string)$item->discount,
                        'gtin'=>$item->product?->ean_gtin,
                        'quantity'=>(string)$item->quantity,
                        'unit_price'=>(string)$item->unit_price,
                        'line_total'=>(string)$item->line_total,
                        'tax_defaults'=>$item->product?->tax_defaults
                            ?? $item->service?->tax_defaults
                            ?? AppSetting::groupValues('tax',[]),
                        'fiscal_tax_group_id'=>$item->product?->fiscal_tax_group_id ?? $item->service?->fiscal_tax_group_id,
                        'origin'=>$item->product?->origin,
                        'ncm'=>$item->product?->ncm,
                        'cest'=>$item->product?->cest,
                        'service_list_item'=>$item->service?->service_list_item,
                        'cnae'=>$item->service?->cnae,
                        'municipal_tax_code'=>$item->service?->municipal_tax_code,
                        'national_tax_code'=>$item->service?->national_tax_code,
                        'nbs'=>$item->service?->nbs,
                    ])->values()->all(),
                    'payments'=>$sale->payments->map(fn($payment)=>[
                        'payment_method'=>$payment->payment_method,
                        'payment_kind'=>\App\Models\PaymentMethod::query()->where('code',$payment->payment_method)->value('kind'),
                        'amount'=>(string)$payment->amount,
                        'due_date'=>optional($payment->due_date)->toDateString(),
                        'integration_type'=>$payment->integration_type,
                        'transaction_document'=>$payment->transaction_document,
                        'transaction_state'=>$payment->transaction_state,
                        'institution_document'=>$payment->institution_document,
                        'card_brand'=>$payment->card_brand,
                        'authorization_code'=>$payment->authorization_code,
                        'beneficiary_document'=>$payment->beneficiary_document,
                        'terminal_id'=>$payment->terminal_id,
                        'receivable'=>(bool)$payment->receivable,
                    ])->values()->all(),
                ],
                'prepared_at'=>now(),
            ]);


            if ($documentType === 'nfse') {
                // Códigos oficiais são obtidos do grupo do serviço, nunca do caixa.
                try {
                    app(\App\Services\Fiscal\NFSeTaxRuleApplicationService::class)->apply($job->id);
                } catch (\RuntimeException $e) {
                    // A venda não se perde: manter a NFS-e preparada com a
                    // pendência do cadastro, sem fabricar impostos.
                    $job->update(['error_message'=>'Cadastro do serviço: '.$e->getMessage()]);
                }
            }
            if ($documentType === 'nfce') {
                // As regras configuradas são aplicadas ao documento ao prepará-lo.
                // Não depende de conferência ou botão do operador.
                try {
                    app(\App\Services\Fiscal\NFCeTaxRuleApplicationService::class)
                        ->apply($job->id);
                } catch (\RuntimeException $e) {
                    // A venda já foi concluída. Preservar o documento reservado,
                    // com pendência fiscal; o preflight impedirá a transmissão.
                    $job->update(['error_message' => 'Configuração fiscal: '.$e->getMessage()]);
                }
            }
        },3);
    }
}
