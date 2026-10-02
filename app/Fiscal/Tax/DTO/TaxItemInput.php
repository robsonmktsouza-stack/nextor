<?php

namespace App\Fiscal\Tax\DTO;

use App\Fiscal\Tax\ValueObjects\Money;
use App\Fiscal\Tax\ValueObjects\Quantity;
use App\Fiscal\Tax\ValueObjects\UnitPrice;
use App\Models\Product;

final readonly class TaxItemInput
{
    public function __construct(
        public Product $product,
        public Quantity $quantity,
        public UnitPrice $unitPrice,
        public ?Money $discount = null,
        public ?Money $freight = null,
        public ?Money $insurance = null,
        public ?Money $other = null,
    ) {
    }

    public function discountValue(): Money
    {
        return $this->discount ?? Money::zero();
    }

    public function freightValue(): Money
    {
        return $this->freight ?? Money::zero();
    }

    public function insuranceValue(): Money
    {
        return $this->insurance ?? Money::zero();
    }

    public function otherValue(): Money
    {
        return $this->other ?? Money::zero();
    }
}
