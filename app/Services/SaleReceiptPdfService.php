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

        // Logo é opcional: uma imagem enorme não pode travar o servidor Laravel.
        // PNG e WebP grandes são reduzidos com GD (rotinas nativas em C) quando
        // disponível; sem GD, somente PNG pequeno passa ao leitor PHP.
        if ($company->logo_path && Storage::disk('public')->exists($company->logo_path)) {
            $disk=Storage::disk('public');
            $size=$disk->size($company->logo_path);
            if ($size>0 && $size<=4194304) {
                $logo=$this->prepareLogo($disk->get($company->logo_path));
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
    /**
     * Evita consumo excessivo de CPU no servidor PHP de desenvolvimento.
     * A miniatura é suficiente para um recibo físico de 58/80 mm.
     */
    private function prepareLogo(string $bytes): ?string
    {
        $info=@getimagesizefromstring($bytes);
        if (!$info || ($info[0] ?? 0)<1 || ($info[1] ?? 0)<1) return null;

        $width=(int)$info[0];
        $height=(int)$info[1];
        $pixels=$width*$height;
        $mime=(string)($info['mime'] ?? '');

        if ($mime==='image/jpeg' && ($info['channels'] ?? 3)===3) return $bytes;
        if (!in_array($mime,['image/png','image/webp'],true) || $pixels>12000000) return null;

        // Sem GD, usar o decodificador PHP apenas para imagens pequenas.
        if (!function_exists('imagecreatefromstring')
            || !function_exists('imagecreatetruecolor')
            || !function_exists('imagecopyresampled')
            || !function_exists('imagejpeg')) {
            return $mime==='image/png' && $pixels<=250000 ? $bytes : null;
        }

        // PNG pequeno pode continuar transparente usando a rotina existente.
        if ($mime==='image/png' && $pixels<=250000) return $bytes;

        $source=@imagecreatefromstring($bytes);
        if ($source===false) return null;
        $scale=min(1.0,480/$width,240/$height);
        $w=max(1,(int)round($width*$scale));
        $h=max(1,(int)round($height*$scale));
        $target=imagecreatetruecolor($w,$h);
        if ($target===false) {
            imagedestroy($source);
            return null;
        }

        // Transparência de PNG/WebP sobre branco: cor natural de papel térmico.
        $white=imagecolorallocate($target,255,255,255);
        imagefilledrectangle($target,0,0,$w,$h,$white);
        imagecopyresampled($target,$source,0,0,0,0,$w,$h,$width,$height);
        ob_start();
        $ok=imagejpeg($target,null,86);
        $jpg=ob_get_clean();
        imagedestroy($target);
        imagedestroy($source);
        return $ok && is_string($jpg) ? $jpg : null;
    }

}
