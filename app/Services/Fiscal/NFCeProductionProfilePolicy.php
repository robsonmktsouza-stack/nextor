<?php

namespace App\Services\Fiscal;

/**
 * Compatibilidade de consultas a perfis fiscais já cadastrados.
 * A emissão é controlada por FiscalDocumentSettings e cálculo tributário;
 * esta classe não bloqueia nem autoriza transmissão.
 */
final class NFCeProductionProfilePolicy
{
    /** @var list<string> */
    private array $approved;

    public function __construct(?array $approved = null)
    {
        $configured = $approved ?? (array) \App\Models\AppSetting::value('nfce','approved_profiles',[]);
        $this->approved = array_values(array_unique(array_map('strtoupper',
            array_map('trim', array_filter($configured, 'is_string')))));
    }

    public function key(string $crt, array $tax): string
    {
        $cfop = (string) ($tax['cfop_outbound_internal'] ?? $tax['nfce_cfop'] ?? $tax['cfop'] ?? '');
        $icms = in_array($crt, ['2','3'], true)
            ? (string) ($tax['icms_cst'] ?? '')
            : (string) ($tax['icms_csosn'] ?? $tax['csosn'] ?? '');
        $pis = (string) ($tax['pis_cst'] ?? '');
        $cofins = (string) ($tax['cofins_cst'] ?? '');
        return strtoupper(implode(':', ['BA','65',$crt,$cfop,$icms,$pis,$cofins]));
    }

    public function approved(string $crt, array $tax): bool
    {
        return in_array($this->key($crt, $tax), $this->approved, true);
    }
}
