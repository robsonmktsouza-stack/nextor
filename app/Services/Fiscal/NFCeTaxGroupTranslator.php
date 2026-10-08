<?php

namespace App\Services\Fiscal;

use App\Models\FiscalTaxGroup;
use RuntimeException;

/**
 * Converte apenas grupos comprovadamente compatíveis com o emissor inicial.
 * Nao calcula tributos ainda nao suportados.
 */
final class NFCeTaxGroupTranslator
{
    public function translate(FiscalTaxGroup $group): array
    {
        if (!$group->is_active || $group->kind !== 'products') {
            throw new RuntimeException('Grupo tributário inativo ou não destinado a produtos.');
        }

        $pattern=(string) $group->cfop_pattern;
        $cfop=preg_match('/^x\d{3}$/',$pattern) ? '5'.substr($pattern,1) : $pattern;
        $csosn=(string) ($group->nfce_csosn ?: $group->icms_csosn);
        if (!preg_match('/^5\d{3}$/',$cfop)
            || $csosn !== '102'
            || $group->pis_cst !== '49'
            || $group->cofins_cst !== '49') {
            throw new RuntimeException('Grupo "'.$group->name.'": o emissor inicial exige CFOP 5xxx, CSOSN 102 e CST 49 para PIS/COFINS; complete o suporte tributário antes de usar outra classificação.');
        }

        $config=$group->tax_config ?? [];
        foreach ($config as $name=>$value) {
            if (!str_ends_with((string)$name,'_rate') && $name !== 'mva_rate') {
                continue;
            }
            if ($value!==null && $value!=='' && abs((float)$value)>0.00001) {
                throw new RuntimeException('Grupo "'.$group->name.'": a alíquota '.$name.' foi informada, mas seu cálculo ainda não está implementado na NFC-e atual.');
            }
        }
        if ($group->icms_cst || $group->ipi_cst) {
            throw new RuntimeException('Grupo "'.$group->name.'": CST do ICMS normal ou IPI informado; o emissor BA/Simples atual não contempla esse tratamento.');
        }

        return [
            'cfop_outbound_internal'=>$cfop,
            'nfce_cfop'=>$cfop,
            'cfop'=>$cfop,
            'icms_csosn'=>$csosn,
            'pis_cst'=>$group->pis_cst,
            'cofins_cst'=>$group->cofins_cst,
            'fiscal_group_id'=>$group->id,
            'fiscal_group_revision'=>$group->revision,
            'fiscal_group_name'=>$group->name,
        ];
    }
}
