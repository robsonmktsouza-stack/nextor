<?php

namespace App\Fiscal\Tax\DTO;

use App\Fiscal\Tax\ValueObjects\Money;

final readonly class ResolvedTaxItem
{
    public function __construct(
        public TaxItemInput $input,
        public string $cfop,
        public Money $gross,
        public Money $pisCofinsBase,
        public Money $ibsCbsBase,
        public Money $itemTotal,
        public FiscalItemTaxResult $tax,
    ) {
    }

    public function toSnapshotArray(): array
    {
        $product = $this->input->product;
        $gtin = trim((string) ($product->ean_gtin ?? ''));
        $gtin = $gtin === '' ? 'SEM GTIN' : $gtin;

        $item = [
            'code' => (string) $product->sku,
            'gtin' => $gtin,
            'description' => (string) $product->name,
            'ncm' => preg_replace('/\D/', '', (string) $product->ncm) ?? '',
            'cfop' => $this->cfop,
            'unit' => (string) $product->unit,
            'quantity' => $this->input->quantity->toString(),
            'unit_price' => $this->input->unitPrice->toString(),
            'gross_total' => $this->gross->toString(),
            'tax_gtin' => $gtin,
            'tax_unit' => (string) $product->unit,
            'tax_quantity' => $this->input->quantity->toString(),
            'tax_unit_price' => $this->input->unitPrice->toString(),
            'include_total' => '1',
            'tax' => $this->tax->toXmlArray(),
            'total_item' => $this->itemTotal->toString(),
        ];

        if ($product->cest) {
            $item['cest'] = preg_replace('/\D/', '', (string) $product->cest) ?? '';
        }

        if ($product->fiscal_benefit_code !== null && $product->fiscal_benefit_code !== '') {
            $item['benefit_code'] = (string) $product->fiscal_benefit_code;
        }

        if ($product->ipi_exception) {
            $item['ipi_exception'] = preg_replace('/\D/', '', (string) $product->ipi_exception) ?? '';
        }

        foreach ([
            'discount' => $this->input->discountValue(),
            'freight' => $this->input->freightValue(),
            'insurance' => $this->input->insuranceValue(),
            'other' => $this->input->otherValue(),
        ] as $field => $money) {
            if ($money->compare(Money::zero()) > 0) {
                $item[$field] = $money->toString();
            }
        }

        return $item;
    }
}
