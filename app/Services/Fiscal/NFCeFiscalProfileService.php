<?php

namespace App\Services\Fiscal;

use App\Models\FiscalFcpRule;
use App\Models\FiscalTaxGroup;
use App\Models\Product;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Um único ponto de decisão fiscal para prévia e NFC-e preparada.
 * Retorna classificação, nunca transmite nem altera estoque/financeiro.
 */
final class NFCeFiscalProfileService
{
    public function __construct(
        private readonly FiscalTaxRuleResolver $rules,
        private readonly NFCeTaxGroupTranslator $groups,
    ) {}

    /**
     * @return array{origin:string,name:string,revision:int,tax:array}
     */
    public function classify(array $item, ?string $date = null): array
    {
        if (($item['item_type'] ?? '') !== 'product') {
            throw new RuntimeException('Este documento aceita somente produtos cadastrados.');
        }

        $day = $date ? Carbon::parse($date)->toDateString() : now()->toDateString();
        $ncm = preg_replace('/\D/', '', (string) ($item['ncm'] ?? ''));
        if (!preg_match('/^\d{8}$/', $ncm)) {
            throw new RuntimeException('Informe um NCM de oito dígitos no produto.');
        }

        $hasFcp = FiscalFcpRule::query()
            ->where('uf', 'BA')
            ->where('is_active', true)
            ->where('rate', '>', 0)
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhereDate('valid_from', '<=', $day))
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $day))
            ->get()
            ->contains(fn (FiscalFcpRule $rule) =>
                !$rule->ncm_prefix || str_starts_with($ncm, $rule->ncm_prefix));
        if ($hasFcp) {
            throw new RuntimeException(
                'Existe regra ativa de FCP para a UF/NCM. O cálculo correspondente ainda não está disponível para esta NFC-e.'
            );
        }

        $explicitId = (int) ($item['fiscal_tax_group_id'] ?? 0);
        if ($explicitId > 0) {
            $group = FiscalTaxGroup::query()->find($explicitId);
            if (!$group) {
                throw new RuntimeException('O grupo tributário associado ao produto não foi encontrado.');
            }
            return $this->fromGroup($group, 'Grupo do produto');
        }

        $rule = $this->rules->resolve('nfce', 'BA', 'BA', '1', $item, $day);
        if ($rule) {
            if (!preg_match('/^5\d{3}$/', $rule->cfop)
                || $rule->csosn !== '102'
                || $rule->pis_cst !== '49'
                || $rule->cofins_cst !== '49') {
                throw new RuntimeException('A regra "'.$rule->name.'" não é compatível com a NFC-e disponível.');
            }
            return [
                'origin' => 'Regra específica',
                'name' => $rule->name,
                'revision' => (int) $rule->revision,
                'tax' => [
                    'cfop_outbound_internal' => $rule->cfop,
                    'nfce_cfop' => $rule->cfop,
                    'cfop' => $rule->cfop,
                    'icms_csosn' => $rule->csosn,
                    'pis_cst' => $rule->pis_cst,
                    'cofins_cst' => $rule->cofins_cst,
                    'fiscal_rule_id' => $rule->id,
                    'fiscal_rule_revision' => $rule->revision,
                    'fiscal_rule_name' => $rule->name,
                ],
            ];
        }

        $defaults = FiscalTaxGroup::query()
            ->where('kind', 'products')
            ->where('is_active', true)
            ->where('is_default', true)
            ->limit(2)->get();
        if ($defaults->count() > 1) {
            throw new RuntimeException('Há mais de um grupo tributário padrão para produtos.');
        }
        if (!$defaults->first()) {
            throw new RuntimeException('Nenhuma regra fiscal ativa ou grupo padrão corresponde ao produto.');
        }
        return $this->fromGroup($defaults->first(), 'Grupo padrão');
    }

    public function classifyProduct(Product $product, ?string $date = null): array
    {
        if (!$product->is_active) {
            throw new RuntimeException('O produto está inativo.');
        }

        return $this->classify([
            'item_type' => 'product',
            'product_id' => $product->id,
            'fiscal_tax_group_id' => $product->fiscal_tax_group_id,
            'ncm' => $product->ncm,
        ], $date);
    }

    private function fromGroup(FiscalTaxGroup $group, string $origin): array
    {
        return [
            'origin' => $origin,
            'name' => $group->name,
            'revision' => (int) $group->revision,
            'tax' => $this->groups->translate($group),
        ];
    }
}
