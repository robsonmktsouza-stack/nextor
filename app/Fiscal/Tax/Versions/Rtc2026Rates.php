<?php

namespace App\Fiscal\Tax\Versions;

use App\Fiscal\Tax\Exceptions\TaxConfigurationException;
use App\Fiscal\Tax\ValueObjects\TaxRate;

final class Rtc2026Rates
{
    public const IBS_UF = '0.1000';
    public const IBS_MUN = '0.0000';
    public const CBS = '0.9000';

    private function __construct()
    {
    }

    public static function assertConfigured(
        TaxRate $ibsUf,
        TaxRate $ibsMun,
        TaxRate $cbs,
    ): void {
        $expected = [
            'pIBSUF' => [self::IBS_UF, $ibsUf->toString()],
            'pIBSMun' => [self::IBS_MUN, $ibsMun->toString()],
            'pCBS' => [self::CBS, $cbs->toString()],
        ];

        foreach ($expected as $field => [$required, $actual]) {
            if ($required !== $actual) {
                throw new TaxConfigurationException(
                    "{$field} configurado como {$actual}; para emissão em 2026 a regra vigente exige {$required}."
                );
            }
        }
    }
}
