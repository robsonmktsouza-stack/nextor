<?php

namespace App\Services\Fiscal;

use App\Models\FiscalFcpRule;
use App\Models\FiscalTaxGroup;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Utiliza exclusivamente a configuração cadastrada ao preparar a NFC-e.
 * Não transmite nem altera estoque, venda ou financeiro.
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
    public function classify(array $item, ?string $date = null, string $crt = '1'): array
    {
        if (($item['item_type'] ?? '') !== 'product') {
            throw new RuntimeException('Este documento aceita somente produtos cadastrados.');
        }

        $day = $date ? Carbon::parse($date)->toDateString() : now()->toDateString();
        $ncm = preg_replace('/\D/', '', (string) ($item['ncm'] ?? ''));
        if (!preg_match('/^\d{8}$/', $ncm)) {
            throw new RuntimeException('Informe um NCM de oito dígitos no produto.');
        }

        // Não descartar alíquotas configuradas no cadastro do produto:
        // se o emissor atual não calcula o tributo, não transmitir com zero.
        $savedTaxes = is_array($item['tax_defaults'] ?? null) ? $item['tax_defaults'] : [];
        foreach ([
            'icms_rate', 'icms_st_rate', 'base_reduction_rate', 'simple_credit_rate',
            'mva_rate', 'fcp_rate', 'ipi_rate',
            'cbs_rate', 'ibs_uf_rate', 'ibs_municipal_rate',
        ] as $field) {
            if (in_array($crt, ['2','3'], true) && in_array($field, ['icms_rate','base_reduction_rate'], true)) {
                continue;
            }
            $value = $savedTaxes[$field] ?? null;
            if ($value !== null && $value !== '' && is_numeric($value)
                && abs((float) $value) > 0.00001) {
                throw new RuntimeException(
                    'O produto possui '.$field.' configurado, mas o cálculo correspondente ainda não está disponível nesta NFC-e.'
                );
            }
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
            return $this->fromGroup($group, 'Grupo do produto', $crt);
        }

        $rule = in_array($crt, ['2','3'], true)
            ? null // Regras legadas do Simples possuem CSOSN e não CST normal.
            : $this->rules->resolve('nfce', 'BA', 'BA', $crt, $item, $day);
        if ($rule) {
            if (!in_array($rule->csosn, ['102','103','300','400','500'], true)
                || !in_array($rule->pis_cst, ['01','02','03','04','06','07','08','09','49','99'], true)
                || !in_array($rule->cofins_cst, ['01','02','03','04','06','07','08','09','49','99'], true)
                || !in_array($rule->cfop, $rule->csosn === '500'
                    ? ['5405','5656','5667'] : ['5101','5102','5103','5104','5115'], true)) {
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
        if ($defaults->first()) {
            return $this->fromGroup($defaults->first(), 'Grupo padrão', $crt);
        }

        // Compatibilidade: produtos já parametrizados antes do cadastro de
        // grupos mantêm seus códigos fiscais, sem enquadramento presumido.
        $tax = is_array($item['tax_defaults'] ?? null) ? $item['tax_defaults'] : [];
        $cfop = (string) ($tax['cfop_outbound_internal'] ?? $tax['nfce_cfop'] ?? $tax['cfop'] ?? '');
        $csosn = (string) ($tax['icms_csosn'] ?? $tax['csosn'] ?? $tax['icms_csosn_default'] ?? '');
        $normal = in_array($crt, ['2','3'], true);
        $icmsCst = (string) ($tax['icms_cst'] ?? $tax['icms_cst_default'] ?? '');
        $pis = (string) ($tax['pis_cst'] ?? $tax['pis_cst_default'] ?? '');
        $cofins = (string) ($tax['cofins_cst'] ?? $tax['cofins_cst_default'] ?? '');

        if ($cfop !== '' || ($normal ? $icmsCst !== '' : $csosn !== '') || $pis !== '' || $cofins !== '') {
            if (($normal && !in_array($icmsCst, ['00','20','40','41'], true))
                || (!$normal && !in_array($csosn, ['102','103','300','400','500'], true))
                || !in_array($pis, ['01','02','03','04','06','07','08','09','49','99'], true)
                || !in_array($cofins, ['01','02','03','04','06','07','08','09','49','99'], true)
                || !in_array($cfop, (!$normal && $csosn === '500')
                    ? ['5405','5656','5667'] : ['5101','5102','5103','5104','5115'], true)) {
                throw new RuntimeException(
                    'Os dados fiscais do produto estão incompletos ou não são suportados nesta NFC-e. Atualize a tributação configurada.'
                );
            }
            return [
                'origin' => 'Cadastro fiscal',
                'name' => 'Configuração do produto',
                'revision' => 1,
                'tax' => [
                    'cfop_outbound_internal' => $cfop,
                    'nfce_cfop' => $cfop,
                    'cfop' => $cfop,
                    ($normal ? 'icms_cst' : 'icms_csosn') => ($normal ? $icmsCst : $csosn),
                    'pis_cst' => $pis,
                    'cofins_cst' => $cofins,
                    'fiscal_config_source' => 'product',
                ],
            ];
        }

        throw new RuntimeException('Configure o grupo tributário ou os dados fiscais completos do produto.');
    }

    private function fromGroup(FiscalTaxGroup $group, string $origin, string $crt): array
    {
        return [
            'origin' => $origin,
            'name' => $group->name,
            'revision' => (int) $group->revision,
            'tax' => $this->groups->translate($group, $crt),
        ];
    }
}
