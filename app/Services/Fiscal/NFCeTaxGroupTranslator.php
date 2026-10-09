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
    public function translate(FiscalTaxGroup $group, string $crt = '1'): array
    {
        if (!$group->is_active || $group->kind !== 'products') {
            throw new RuntimeException('Grupo tributário inativo ou não destinado a produtos.');
        }

        $pattern=(string) $group->cfop_pattern;
        $cfop=preg_match('/^x\d{3}$/',$pattern) ? '5'.substr($pattern,1) : $pattern;
        $csosn=(string) ($group->nfce_csosn ?: $group->icms_csosn);
        $normal = in_array($crt, ['2','3'], true);
        $icmsCst = (string)$group->icms_cst;
        if (($normal && !in_array($icmsCst, ['00','20'], true))
            || (!$normal && !in_array($csosn, ['102','103','300','400','500'], true))
            || !in_array((string)$group->pis_cst, ['01','02','03','04','06','07','08','09','49','99'], true)
            || !in_array((string)$group->cofins_cst, ['01','02','03','04','06','07','08','09','49','99'], true)) {
            throw new RuntimeException('Grupo "'.$group->name.'": código fiscal sem cálculo NFC-e homologado.');
        }
        $permittedCfops = !$normal && $csosn === '500'
            ? ['5405','5656','5667']
            : ['5101','5102','5103','5104','5115'];
        if (!in_array($cfop, $permittedCfops, true)) {
            throw new RuntimeException('Grupo "'.$group->name.'": CFOP incompatível com a classificação de ICMS na NFC-e.');
        }

        $config=$group->tax_config ?? [];
        foreach ($config as $name=>$value) {
            if (in_array($name, ['pis_rate', 'cofins_rate', 'pis_quantity_rate', 'cofins_quantity_rate'], true)
                || ($normal && in_array($name, ['icms_rate','base_reduction_rate'], true))) {
                continue; // Cálculo percentual é feito no serviço fiscal.
            }
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
        foreach (['pis','cofins'] as $kind) {
            $method = (string)($config[$kind.'_calc_type'] ?? 'none');
            $rate = $config[$kind.'_rate'] ?? null;
            if ($method === 'percentage') {
                if (!in_array((string)$group->{$kind.'_cst'}, ['01','02','49','99'], true)
                    || $rate === null || $rate === '' || !is_numeric($rate)) {
                    throw new RuntimeException('Grupo "'.$group->name.'": '.$kind.'_calc_type exige CST compatível e alíquota configurada.');
                }
            } elseif ($method === 'quantity') {
                $quantityRate = $config[$kind.'_quantity_rate'] ?? null;
                if (!in_array((string)$group->{$kind.'_cst'}, ['03','49','99'], true)
                    || $quantityRate === null || $quantityRate === '' || !is_numeric($quantityRate)
                    || ($rate !== null && $rate !== '' && (float)$rate > 0)) {
                    throw new RuntimeException('Grupo "'.$group->name.'": '.$kind.'_calc_type exige CST por quantidade e alíquota unitária.');
                }
            } elseif (!in_array($method, ['','none'], true)
                || ($rate !== null && $rate !== '' && (float)$rate > 0)) {
                throw new RuntimeException('Grupo "'.$group->name.'": '.$kind.'_calc_type não corresponde ao cálculo e alíquota configurados.');
            }
            if (!in_array(($config[$kind.'_st_calc_type'] ?? null),[null,'','none'],true)) {
                throw new RuntimeException('Grupo "'.$group->name.'": '.$kind.'_st_calc_type ainda não é implementado na NFC-e atual.');
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
        if (($group->icms_cst && !$normal) || $group->ipi_cst) {
            throw new RuntimeException('Grupo "'.$group->name.'": CST do ICMS normal ou IPI incompatível com este regime e emissor.');
        }
        if ($normal && (
            !isset($config['icms_rate']) || $config['icms_rate'] === ''
            || !isset($config['mod_bc']) || $config['mod_bc'] === ''
            || ($icmsCst === '20' && (!isset($config['base_reduction_rate']) || $config['base_reduction_rate'] === ''))
        )) {
            throw new RuntimeException('Grupo "'.$group->name.'": configure modalidade, ICMS e redução de base quando aplicável.');
        }

        $mapped = [
            'cfop_outbound_internal'=>$cfop,
            'nfce_cfop'=>$cfop,
            'cfop'=>$cfop,
            ($normal ? 'icms_cst' : 'icms_csosn')=>($normal ? $icmsCst : $csosn),
            'pis_cst'=>$group->pis_cst,
            'cofins_cst'=>$group->cofins_cst,
            'fiscal_group_id'=>$group->id,
            'fiscal_group_revision'=>$group->revision,
            'fiscal_group_name'=>$group->name,
        ];
        foreach (['pis_rate','cofins_rate','pis_quantity_rate','cofins_quantity_rate','pis_calc_type','cofins_calc_type',
            'icms_rate','base_reduction_rate','mod_bc'] as $field) {
            if (array_key_exists($field,$config) && $config[$field] !== '') {
                $mapped[$field] = $config[$field];
            }
        }
        return $mapped;
    }
}
