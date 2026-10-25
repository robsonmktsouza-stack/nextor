<?php

namespace App\Services\Fiscal;

use RuntimeException;

final class Nfe55TaxParameters
{
    public static function forDirection(array $tax, string $direction): array
    {
        if ($direction === 'inbound') {
            foreach ($tax as $key => $value) {
                if (str_ends_with((string) $key, '_inbound') && $value !== null && $value !== '') {
                    $tax[substr($key, 0, -8)] = $value;
                }
            }
        }
        foreach ([
            'mod_bc'=>'icms_base_mode','base_reduction_rate'=>'icms_base_reduction',
            'simple_credit_rate'=>'icms_credit_rate','mod_bc_st'=>'icms_st_base_mode',
            'mva_rate'=>'icms_st_mva','icms_deferral_rate'=>'icms_deferment_rate',
            'cenq_ipi'=>'ipi_enq','pis_calc_type'=>'pis_calculation_type',
            'cofins_calc_type'=>'cofins_calculation_type',
            'interstate_icms_rate'=>'difal_interstate_rate','internal_icms_rate'=>'difal_internal_rate',
            'fcp_interstate_rate'=>'difal_fcp_rate','icms_desonerated_value'=>'icms_desoneration_amount',
        ] as $legacy=>$canonical) {
            if ((!isset($tax[$canonical]) || $tax[$canonical] === '') && isset($tax[$legacy]) && $tax[$legacy] !== '') {
                $tax[$canonical] = $tax[$legacy];
            }
        }
        foreach (['pis','cofins'] as $name) {
            if (($tax[$name.'_calculation_type'] ?? '') === 'percentage') $tax[$name.'_calculation_type'] = 'percent';
        }
        return $tax;
    }

    /** Refuse supplied groups whose fiscal content would otherwise be silently discarded. */
    public static function assertSupported(array $tax): void
    {
        foreach ($tax as $key=>$value) {
            if ($value === null || $value === '' || $value === false || $value === []) continue;
            $name=(string)$key;
            if (str_ends_with($name,'_inbound') || str_ends_with($name,'_export')) continue;
            $implemented=['st_retained_amount_scope','icms_st_retained_base','st_retained_rate',
                'icms_substitutor_value','icms_st_retained_value','fcp_st_retained_base',
                'fcp_st_retained_rate','fcp_st_retained_value','icms_effective_base',
                'icms_effective_rate','icms_effective_value','icms_effective_reduction',
                'icms_desoneration_amount','icms_desoneration_reason','icms_desoneration_deduct',
                'icms_desonerated_value','difal_base','difal_fcp_base','difal_internal_rate',
                'difal_interstate_rate','difal_share_rate','difal_fcp_rate',
                'interstate_icms_rate','internal_icms_rate','fcp_interstate_rate'];
            if (in_array($name,$implemented,true)) continue;
            $groupCode=in_array($name,['is_cst','is_class','is_classification','is_classification_code'],true);
            $unsupported=(bool)preg_match('/^(?:pis|cofins)_st(?:_|$)|(?:desoner|retained|substitutor|monoph|monofas|presumed_credit|presumido|transfer_credit|credito_presumido|regular_tax|tributacao_regular)/i',$name)
                || preg_match('/^(?:icms_uf_dest|icms_uf_remet|difal|is_|gIS|ibs_credit|cbs_credit|ibs_mono|cbs_mono)/i',$name)
                || preg_match('/^(?:ibs|cbs|fcp)_.*(?:deferral|deferment|differ|credit|presum|monoph|regular|adjust|transfer)/i',$name)
                || in_array($name,['interstate_icms_rate','internal_icms_rate','fcp_interstate_rate'],true);
            if (!$groupCode && !$unsupported) continue;
            $codeLike=$groupCode || (bool)preg_match('/(?:_cst|_class|_reason|_mode)$/D',$name);
            if (!$codeLike && is_numeric($value) && (float)$value === 0.0) continue;
            throw new RuntimeException('O parâmetro '.$name.' exige grupo fiscal específico ainda não implementado para NF-e.');
        }
    }
}
