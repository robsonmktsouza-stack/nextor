<?php

namespace App\Fiscal\Tax\ValueObjects;

use App\Fiscal\Tax\Exceptions\InvalidDecimalException;
use App\Fiscal\Tax\Support\DecimalMath;

final readonly class TaxRate
{
    private string $value;

    public function __construct(mixed $value)
    {
        if (!is_string($value)) {
            throw new InvalidDecimalException('TaxRate aceita somente string decimal; float/int são proibidos.');
        }

        $this->value = DecimalMath::normalize($value, 4, 4);
    }

    public function toString(): string
    {
        return $this->value;
    }
}
