<?php

namespace App\Fiscal\Tax\ValueObjects;

use App\Fiscal\Tax\Exceptions\InvalidDecimalException;
use App\Fiscal\Tax\Support\DecimalMath;

final readonly class Money
{
    private string $value;

    public function __construct(mixed $value)
    {
        if (!is_string($value)) {
            throw new InvalidDecimalException('Money aceita somente string decimal; float/int são proibidos.');
        }

        $this->value = DecimalMath::normalize($value, 2, 2);
    }

    public static function zero(): self
    {
        return new self('0.00');
    }

    public function add(self $other): self
    {
        return new self(DecimalMath::add($this->value, $other->value, 2));
    }

    public function subtract(self $other): self
    {
        return new self(DecimalMath::subtract($this->value, $other->value, 2));
    }

    public function applyRate(TaxRate $rate): self
    {
        return new self(DecimalMath::percent($this->value, 2, $rate->toString(), 4, 2));
    }

    public function compare(self $other): int
    {
        return DecimalMath::compare($this->value, $other->value, 2);
    }

    public function toString(): string
    {
        return $this->value;
    }
}
