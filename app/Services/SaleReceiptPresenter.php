<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\CompanySetting;
use App\Models\FiscalDocumentJob;
use App\Models\PaymentMethod;
use App\Models\Sale;

/**
 * Dados do mesmo comprovante comercial usado no PDV e no módulo Vendas.
 * Não prepara NFC-e nem altera venda, caixa, estoque ou financeiro.
 */
final class SaleReceiptPresenter
{
    public function data(Sale $sale, bool $fromPdv = false): array
    {
        $sale->load([
            'customer:id,name,document',
            'user:id,name',
            'items.product:id,unit',
            'payments',
        ]);

        $methods=PaymentMethod::query()
            ->orderBy('sort_order')->get(['code','name','kind']);

        $cashReceived=(float)($sale->cash_received ?? $sale->total);
        $change=(float)($sale->change_amount ?? 0);

        if ($fromPdv) {
            $cashReceived=(float)session('pdv_cash_received',$cashReceived);
            $change=(float)session('pdv_change',$change);
            session()->keep(['pdv_last_sale','pdv_change']);
        }

        return [
            'receiptOrigin'=>$fromPdv ? 'pdv' : 'sales',
            'sale'=>$sale,
            'company'=>CompanySetting::current(),
            'paymentLabels'=>$methods->pluck('name','code')->all(),
            'paymentKinds'=>$methods->pluck('kind','code')->all(),
            'pdvSettings'=>AppSetting::groupValues('pdv',[
                'receipt_width'=>'80',
                'receipt_copies'=>1,
            ]),
            'cashReceived'=>$cashReceived,
            'change'=>$change,
            'nfceDocument'=>FiscalDocumentJob::query()
                ->where('sale_id',$sale->id)
                ->where('document_type','nfce')
                ->latest('id')
                ->first(),
            'nfceAuto'=>(bool)AppSetting::value('pdv','auto_nfce',false)
                || (bool)AppSetting::value('nfce','auto_from_pdv',false),
        ];
    }
}
