<?php

namespace App\Services\Fiscal;

use App\Models\AppSetting;

/**
 * Parâmetros fiscais independentes por ambiente. Os campos legados
 * (series/next_number/csc_id/csc_token) são mantidos como homologação,
 * preservando notas já emitidas e configurações existentes.
 *
 * Produção exige valores explicitamente cadastrados: nunca copia o CSC
 * ou o contador de homologação e nunca presume número inicial 1.
 */
final class NFCeEnvironmentParameters
{
    public function series(string $environment): ?int
    {
        $value=AppSetting::value('nfce',$environment==='production'?'production_series':'series',null);
        return is_numeric($value) && (int)$value>=0 && (int)$value<=999
            ? (int)$value : null;
    }

    public function numberKey(string $environment): string
    {
        return $environment==='production' ? 'production_next_number' : 'next_number';
    }

    public function nextNumber(string $environment): ?int
    {
        $value=AppSetting::value('nfce',$this->numberKey($environment),null);
        return is_numeric($value) && (int)$value>=1 && (int)$value<=999999999
            ? (int)$value : null;
    }

    public function cscId(string $environment): string
    {
        return trim((string)AppSetting::value('nfce',$environment==='production'?'production_csc_id':'csc_id',''));
    }

    public function cscToken(string $environment): string
    {
        return trim((string)AppSetting::value('nfce',$environment==='production'?'production_csc_token':'csc_token',''));
    }

    /** @return list<string> */
    public function issues(string $environment): array
    {
        if (!in_array($environment,['homologation','production'],true)) {
            return ['Ambiente de NFC-e inválido.'];
        }
        $label=$environment==='production'?'produção':'homologação';
        $issues=[];
        if ($this->series($environment)===null) {
            $issues[]="Configure a série da NFC-e de {$label}.";
        }
        if ($this->nextNumber($environment)===null) {
            $issues[]="Configure a numeração inicial da NFC-e de {$label}.";
        }
        if ($this->cscId($environment)==='' || !ctype_digit($this->cscId($environment))) {
            $issues[]="Configure o ID do CSC de {$label} fornecido pela SEFAZ.";
        }
        if ($this->cscToken($environment)==='') {
            $issues[]="Configure o CSC de {$label} fornecido pela SEFAZ.";
        }
        return $issues;
    }
}
