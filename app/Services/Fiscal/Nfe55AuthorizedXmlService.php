<?php

namespace App\Services\Fiscal;

use App\Models\AppSetting;
use App\Models\CompanySetting;
use App\Models\NfeDraft;
use App\Support\BrazilianTaxId;
use RuntimeException;

/** Explicit authorization to access the full NF-e XML; independent from technical responsibility. */
final class Nfe55AuthorizedXmlService
{
    private const SEFAZ_BA = '13937073000156';

    public function documents(NfeDraft $draft, CompanySetting $company): array
    {
        $documents=[];
        if ((bool)AppSetting::value('accounting','accountant_xml_authorized',false)) {
            $raw=(string)AppSetting::value('accounting','accountant_document','');
            if (!BrazilianTaxId::valid($raw)) throw new RuntimeException('Informe CPF/CNPJ válido do contador em Configurações → Contabilidade.');
            $documents[]=preg_replace('/\D/','',$raw);
        }
        if (strtoupper((string)$company->state)==='BA'
            && (bool)AppSetting::value('accounting','nfe_ba_sefaz_xml_authorized',false)) {
            // An explicit alternative selected by the operator, never an automatic fallback.
            $documents[]=self::SEFAZ_BA;
        }
        $documents=array_values(array_unique($documents));
        $recipient=preg_replace('/\D/','',(string)data_get($draft->recipient_snapshot,'document',''));
        if (in_array($recipient,$documents,true)) throw new RuntimeException('O destinatário já tem acesso à NF-e; não pode ser repetido como terceiro autorizado no XML.');
        return $documents;
    }

    public function assertRequired(NfeDraft $draft, CompanySetting $company): void
    {
        if (strtoupper((string)$company->state)==='BA' && !$this->documents($draft,$company)) {
            throw new RuntimeException('Bahia exige autorização de acesso ao XML (rejeição 486). Cadastre o CPF/CNPJ do contador e autorize o XML em Configurações → Contabilidade, ou selecione explicitamente a alternativa SEFAZ-BA.');
        }
    }
}
