<?php

namespace App\Support;

use App\Models\CompanySetting;

final class CompanyReadiness
{
    /**
     * Pré-requisito mínimo para operar: identificação da empresa.
     * Certificado/CSC e demais exigências fiscais são verificados no
     * respectivo fluxo fiscal, não para simplesmente abrir o PDV.
     */
    public static function configured(): bool
    {
        $company=CompanySetting::query()->first([
            'document','legal_name','state','city',
        ]);

        if(!$company) return false;

        $document=strtoupper(preg_replace('/[^0-9A-Za-z]/','',(string)$company->document));
        $validDocument=preg_match('/^[A-Z0-9]{12}[0-9]{2}$/',$document)===1;

        return $validDocument
            && filled(trim((string)$company->legal_name))
            && filled(trim((string)$company->state))
            && filled(trim((string)$company->city))
            && InstanceIdentity::matches($company->document);
    }
}
