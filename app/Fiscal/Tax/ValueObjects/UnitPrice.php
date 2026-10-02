<?php

namespace App\Fiscal\Tax\ValueObjects;

use App\Fiscal\Tax\Exceptions\InvalidDecimalException;
use App\Fiscal\Tax\Support\DecimalMath;

final readonly class UnitPrice
{
    private string $value;

    public function __construct(mixed $value)
    {
        if (!is_string($value)) {
            throw new InvalidDecimalException('UnitPrice aceita somente string decimal; float/int são proibidos.');
        }

        $this->value = DecimalMath::normalize($value, 10, 10);
    }

    public function toString(): string
    {
        return $this->value;
    }
}
