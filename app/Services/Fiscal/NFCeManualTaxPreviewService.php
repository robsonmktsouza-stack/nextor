<?php

namespace App\Services\Fiscal;

use App\Models\CompanySetting;
use App\Models\Product;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Simula a classificação fiscal antes de criar uma venda manual da NFC-e.
 *
 * Não altera estoque, pagamentos, numeração ou documentos. A preparação da
 * nota faz NOVAMENTE a classificação autoritativa sobre o snapshot gravado.
 */
final class NFCeManualTaxPreviewService
{
    public function __construct(
        private readonly NFCeFiscalProfileService $profiles,
        private readonly NFCeTaxCalculationService $calculator,
        private readonly NFCeRtcTaxService $rtc,
    ) {}

    /** @param list<array<string,mixed>> $rows */
    public function verify(array $rows): void
    {
        $company=CompanySetting::current();
        $products=Product::query()->whereIn('id',array_column($rows,'product_id'))
            ->get()->keyBy('id');
        $errors=[];

        foreach ($rows as $index=>$row) {
            $product=$products->get((int)$row['product_id']);
            if (!$product || !$product->is_active) {
                $errors['items.'.$index.'.product_id']='Produto não encontrado ou inativo.';
                continue;
            }
            $quantity=(float)$row['quantity'];
            $price=(float)$row['unit_price'];
            $discount=(float)($row['discount'] ?? 0);
            if ($discount > round($quantity*$price,2,PHP_ROUND_HALF_UP)) {
                $errors['items.'.$index.'.discount']='O desconto não pode ultrapassar o total do produto.';
                continue;
            }
            $taxDefaults=is_array($product->tax_defaults) ? $product->tax_defaults : [];
            $item=[
                'item_type'=>'product',
                'product_id'=>$product->id,
                'quantity'=>$row['quantity'],
                'unit_price'=>$row['unit_price'],
                'discount'=>$row['discount'] ?? 0,
                'origin'=>$product->origin,
                'ncm'=>$product->ncm,
                'cest'=>$product->cest,
                'tax_defaults'=>$taxDefaults,
                'fiscal_tax_group_id'=>$product->fiscal_tax_group_id,
            ];
            try {
                $profile=$this->profiles->classify(
                    $item,now()->toDateString(),(string)$company->crt,(string)$company->state
                );
                $item['tax_defaults']=array_replace($taxDefaults,$profile['tax']);
                $legacy=$this->calculator->calculate($item,(string)$company->crt);
                $this->rtc->calculate($item,(string)$company->crt,now()->toDateString(),$legacy);
            } catch (RuntimeException $e) {
                $errors['items.'.$index.'.product_id']='Tributação do produto '.$product->name.': '.$e->getMessage();
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }
}
