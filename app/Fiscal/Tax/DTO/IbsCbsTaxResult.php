<?php

namespace App\Fiscal\Tax\DTO;

use App\Fiscal\Tax\ValueObjects\IbsCbsClassification;
use App\Fiscal\Tax\ValueObjects\Money;
use App\Fiscal\Tax\ValueObjects\TaxRate;

final readonly class IbsCbsTaxResult
{
    public function __construct(
        public IbsCbsClassification $classification,
        public Money $base,
        public TaxRate $ibsUfRate,
        public TaxRate $ibsMunRate,
        public TaxRate $cbsRate,
        public Money $ibsUfAmount,
        public Money $ibsMunAmount,
        public Money $ibsAmount,
        public Money $cbsAmount,
    ) {
    }

    public function toXmlArray(): array
    {
        return [
            'CST' => $this->classification->cst,
            'cClassTrib' => $this->classification->code,
            'gIBSCBS' => [
                'vBC' => $this->base->toString(),
                'gIBSUF' => [
                    'pIBSUF' => $this->ibsUfRate->toString(),
                    'vIBSUF' => $this->ibsUfAmount->toString(),
                ],
                'gIBSMun' => [
                    'pIBSMun' => $this->ibsMunRate->toString(),
                    'vIBSMun' => $this->ibsMunAmount->toString(),
                ],
                'vIBS' => $this->ibsAmount->toString(),
                'gCBS' => [
                    'pCBS' => $this->cbsRate->toString(),
                    'vCBS' => $this->cbsAmount->toString(),
                ],
            ],
        ];
    }
}
