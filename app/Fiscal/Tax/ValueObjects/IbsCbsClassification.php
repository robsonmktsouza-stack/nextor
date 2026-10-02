<?php

namespace App\Fiscal\Tax\ValueObjects;

use App\Fiscal\Tax\Exceptions\TaxConfigurationException;

final readonly class IbsCbsClassification
{
    public function __construct(
        public string $cst,
        public string $code,
    ) {
        if (!preg_match('/^\d{3}$/', $cst)) {
            throw new TaxConfigurationException('CST IBS/CBS deve possuir exatamente 3 dígitos.');
        }

        if (!preg_match('/^\d{6}$/', $code)) {
            throw new TaxConfigurationException('cClassTrib deve possuir exatamente 6 dígitos.');
        }

        if (!str_starts_with($code, $cst)) {
            throw new TaxConfigurationException(
                'Os três primeiros dígitos do cClassTrib devem corresponder ao CST IBS/CBS.'
            );
        }
    }
}
