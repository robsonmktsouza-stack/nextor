<?php

namespace App\Fiscal\Tax\DTO;

use App\Fiscal\Tax\ValueObjects\Money;
use App\Fiscal\Tax\ValueObjects\TaxRate;

final readonly class PisTaxResult
{
    public function __construct(
        public string $cst,
        public Money $base,
        public TaxRate $rate,
        public Money $amount,
    ) {
    }

    public function toXmlArray(): array
    {
        return [
            'PISOutr' => [
                'CST' => $this->cst,
                'vBC' => $this->base->toString(),
                'pPIS' => $this->rate->toString(),
                'vPIS' => $this->amount->toString(),
            ],
        ];
    }
}
