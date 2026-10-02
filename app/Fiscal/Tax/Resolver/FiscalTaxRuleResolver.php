<?php

namespace App\Fiscal\Tax\Resolver;

use App\Fiscal\Models\FiscalCompany;
use App\Fiscal\Tax\Exceptions\TaxConfigurationException;
use App\Fiscal\Tax\Models\FiscalTaxGroup;
use App\Fiscal\Tax\Models\FiscalTaxRule;
use App\Models\Product;
use DateTimeInterface;

final class FiscalTaxRuleResolver
{
    public const SCOPE_INTERNAL_FINAL_CONSUMER_PRESENT = 'internal_final_consumer_present';

    public function resolve(
        FiscalCompany $company,
        Product $product,
        DateTimeInterface $issueAt,
    ): FiscalTaxRule {
        $groupCode = trim((string) ($product->tax_group ?? ''));

        if ($groupCode === '') {
            throw new TaxConfigurationException(
                "Produto {$this->productLabel($product)} não possui grupo tributário fiscal."
            );
        }

        $group = FiscalTaxGroup::query()
            ->where('code', $groupCode)
            ->where('is_active', true)
            ->first();

        if (!$group) {
            throw new TaxConfigurationException(
                "Grupo tributário '{$groupCode}' do produto {$this->productLabel($product)} não existe ou está inativo."
            );
        }

        if ($group->model !== '65') {
            throw new TaxConfigurationException("Grupo tributário '{$groupCode}' não está configurado para NFC-e 65.");
        }

        if ((string) $group->crt !== (string) $company->crt) {
            throw new TaxConfigurationException(
                "Grupo tributário '{$groupCode}' não corresponde ao CRT da empresa emitente."
            );
        }

        $date = $issueAt->format('Y-m-d');

        $rules = $group->rules()
            ->where('operation_scope', self::SCOPE_INTERNAL_FINAL_CONSUMER_PRESENT)
            ->where('is_active', true)
            ->whereDate('effective_from', '<=', $date)
            ->where(function ($query) use ($date) {
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $date);
            })
            ->orderByDesc('effective_from')
            ->get();

        if ($rules->count() === 0) {
            throw new TaxConfigurationException(
                "Grupo tributário '{$groupCode}' não possui regra vigente para venda interna presencial a consumidor final."
            );
        }

        if ($rules->count() > 1) {
            throw new TaxConfigurationException(
                "Grupo tributário '{$groupCode}' possui mais de uma regra fiscal vigente para o mesmo cenário."
            );
        }

        /** @var FiscalTaxRule $rule */
        $rule = $rules->first();

        return $rule;
    }

    private function productLabel(Product $product): string
    {
        return "'".($product->sku ?: $product->name ?: (string) $product->getKey())."'";
    }
}
