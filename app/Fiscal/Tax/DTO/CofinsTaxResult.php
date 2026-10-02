<?php

namespace App\Fiscal\Tax\DTO;

use App\Fiscal\Tax\ValueObjects\Money;
use App\Fiscal\Tax\ValueObjects\TaxRate;

final readonly class CofinsTaxResult
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
            'COFINSOutr' => [
                'CST' => $this->cst,
                'vBC' => $this->base->toString(),
                'pCOFINS' => $this->rate->toString(),
                'vCOFINS' => $this->amount->toString(),
            ],
        ];
    }
}
