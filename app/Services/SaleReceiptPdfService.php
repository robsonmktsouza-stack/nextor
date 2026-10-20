<?php

namespace App\Services;

use App\Models\Sale;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * PDF do comprovante interno. Reaproveita o mesmo Blade e os dados
 * persistidos do recibo térmico do PDV, sem preparar ou transmitir NFC-e.
 */
class SaleReceiptPdfService
{
    public function __construct(private readonly SaleReceiptPresenter $presenter)
    {
    }

    public function renderHtml(Sale $sale): string
    {
        if ($sale->operation_type !== 'sale') {
            throw new RuntimeException('O comprovante é exclusivo de vendas.');
        }

        // False: consulta/histórico nunca reutilizam valores da sessão do PDV.
        $data=$this->presenter->data($sale,false);
        $data['receiptPdfMode']=true;
        $data['receiptPdfLogo']=null;
        $company=$data['company'];
        if ($company->logo_path && Storage::disk('public')->exists($company->logo_path)) {
            $disk=Storage::disk('public');
            $mime=$disk->mimeType($company->logo_path);
            $size=$disk->size($company->logo_path);
            if (in_array($mime,['image/png','image/jpeg','image/webp'],true) && $size<=4194304) {
                $data['receiptPdfLogo']='data:'.$mime.';base64,'.base64_encode($disk->get($company->logo_path));
            }
        }

        return view('pdv.receipt',$data)->render();
    }

    /**
     * Entrega o PDF imediatamente sem iniciar Chrome/Edge, sem fila e sem
     * diretório temporário. Preserva uma página por via (58 ou 80 mm).
     */
    public function render(Sale $sale): string
    {
        if ($sale->operation_type !== 'sale') {
            throw new RuntimeException('O comprovante é exclusivo de vendas.');
        }

        $data=$this->presenter->data($sale,false);
        $company=$data['company'];
        $formatMoney=fn($amount)=>number_format((float)$amount,2,',','.');
        $formatQuantity=function ($amount) {
            $number=number_format((float)$amount,3,',','.');
            return rtrim(rtrim($number,'0'),',');
        };
        $currencyPrefix=$company->show_currency_prefix ? 'R$ ' : '';
        $displayMoney=fn($amount)=>$currencyPrefix.$formatMoney($amount);
        $logo=null;

        if ($company->logo_path && Storage::disk('public')->exists($company->logo_path)) {
            $disk=Storage::disk('public');
            $mime=$disk->mimeType($company->logo_path);
            $size=$disk->size($company->logo_path);
            if (in_array($mime,['image/png','image/jpeg'],true) && $size<=4194304) {
                $logo=$disk->get($company->logo_path);
            }
        }

        $address=collect([
            $company->address,
            $company->address_number,
            $company->district,
            $company->city
                ? $company->city.($company->state ? '/'.$company->state : '')
                : null,
        ])->filter()->implode(' · ');

        $items=$sale->items->map(fn($item)=>[
            'code'=>(string)($item->product_sku ?: '-'),
            'description'=>(string)$item->product_name,
            'quantity'=>$formatQuantity($item->quantity),
            'unit'=>$formatMoney($item->unit_price),
            'amount'=>$formatMoney((float)$item->line_total+(float)$item->discount),
            'discount'=>(float)$item->discount>0?$formatMoney($item->discount):null,
        ])->all();

        $payments=$sale->payments->map(fn($payment)=>[
            'name'=>$data['paymentLabels'][$payment->payment_method] ?? 'Outro',
            'amount'=>$displayMoney($payment->amount),
        ])->all();

        $hasCash=$sale->payments->contains(
            fn($payment)=>($data['paymentKinds'][$payment->payment_method] ?? null)==='cash'
        );

        return (new \App\Services\Receipts\ThermalReceiptPdfWriter(
            (int)($data['pdvSettings']['receipt_width'] ?? 80)
        ))->render([
            'logo'=>$logo,
            'company'=>$company->trade_name ?: $company->legal_name ?: config('app.name','Lumeron'),
            'document'=>$company->document,
            'address'=>$address,
            'contact'=>collect([$company->phone,$company->email])->filter()->implode(' · '),
            'print_header'=>$company->print_header,
            'print_footer'=>$company->print_footer,
            'sale_id'=>$sale->id,
            'date'=>optional($sale->completed_at)->format(\App\Models\AppSetting::dateFormat().' H:i:s')
                ??now()->format(\App\Models\AppSetting::dateFormat().' H:i:s'),
            'operator'=>$sale->user?->name,
            'copies'=>(int)($data['pdvSettings']['receipt_copies'] ?? 1),
            'items'=>$items,
            'subtotal'=>$displayMoney($sale->subtotal),
            'discount'=>(float)$sale->discount_total>0?$displayMoney($sale->discount_total):null,
            'total'=>$displayMoney($sale->total),
            'payments'=>$payments,
            'cash_payment'=>$hasCash,
            'cash_received'=>$displayMoney($data['cashReceived']),
            'change'=>$displayMoney($data['change']),
            'customer'=>$sale->customer?->name ?? 'Consumidor não identificado',
            'consumer_document'=>$sale->consumer_document ?: 'Não informado',
            'consumer_name'=>$sale->consumer_name,
            'notes'=>$sale->notes,
        ]);
    }
}
