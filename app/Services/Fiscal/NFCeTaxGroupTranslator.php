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
        foreach (['ibs_cbs_cst','ibs_cbs_class','is_cst','is_class'] as $name) {
            if (trim((string) ($config[$name] ?? '')) !== '') {
                throw new RuntimeException('Grupo "'.$group->name.'": classificação '.$name.' informada, mas os grupos de IBS/CBS/IS ainda não são gerados pelo XML NFC-e atual.');
            }
        }
        // Outros parâmetros cadastráveis ainda não foram mapeados para o
        // emissor ACBr. Nunca descartá-los silenciosamente ao aplicar regras.
        $unsupported=[
            'fiscal_benefit_code','anp_code','anp_description',
            'fuel_origin_indicator','fuel_origin_uf',
        ];
        foreach ($unsupported as $name) {
            if (trim((string) ($config[$name] ?? '')) !== '') {
                throw new RuntimeException('Grupo "'.$group->name.'": o campo '.$name.' está configurado, mas não é suportado pela NFC-e atual.');
            }
        }
        foreach (['pis_calc_type','pis_st_calc_type','cofins_calc_type','cofins_st_calc_type'] as $name) {
            if (!in_array(($config[$name] ?? null),[null,'','none'],true)) {
                throw new RuntimeException('Grupo "'.$group->name.'": o tipo de cálculo '.$name.' ainda não é implementado na NFC-e atual.');
            }
        }
        if (!empty($config['municipal_variations'])) {
            throw new RuntimeException('Grupo "'.$group->name.'": a emissão com variações municipais ainda não está implementada.');
        }
        // Variações para outras UFs não afetam a NFC-e estritamente interna
        // e devem ser verificadas pelo emissor de NF-e quando implementado.
        if (!empty($config['state_variations'])) {
            foreach ($config['state_variations'] as $variation) {
                if (($variation['uf'] ?? '') === 'BA') {
                    throw new RuntimeException('Grupo "'.$group->name.'": variação ativa para BA não é suportada pela NFC-e atual.');
                }
            }
        }
        if ($group->kind !== 'products' || $group->iss_exigibility) {
            throw new RuntimeException('Grupo "'.$group->name.'": parâmetros de serviços/ISS não são suportados pela NFC-e de mercadorias.');
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
