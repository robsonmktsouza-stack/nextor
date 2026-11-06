<?php

namespace Tests\Unit;

use App\Services\Fiscal\NfeTaxMath;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NfeTaxMathTest extends TestCase
{
    public function test_half_cent_and_large_intermediate_products_are_exact(): void
    {
        self::assertSame(1,NfeTaxMath::percent(1,'50','tax'));
        self::assertSame(999998999999999,NfeTaxMath::percent(999999999999999,'99.9999','tax'));
        self::assertSame('0.01',NfeTaxMath::format(1));
        self::assertSame(432,NfeTaxMath::quantity('3.5','1.2345','tax'));
    }

    public function test_excess_precision_is_not_silently_truncated(): void
    {
        $this->expectException(RuntimeException::class);
        NfeTaxMath::rate('1.23456','tax');
    }

    public function test_infinite_negative_and_exponential_notation_are_rejected(): void
    {
        foreach (['-1','1e4','NAN','INF'] as $value) {
            try { NfeTaxMath::scaled($value,4); self::fail('Invalid decimal accepted.'); }
            catch (RuntimeException) { self::assertTrue(true); }
        }
    }

    public function test_line_uses_the_same_rounded_quantity_that_is_serialized_to_xml(): void
    {
        $line=NfeTaxMath::line('1.2345','1.2345','100');
        self::assertSame(15240,$line['quantity_units']);
        self::assertSame(15240,$line['gross_cents']);
    }

    public function test_proportional_cent_allocation_does_not_overflow_or_lose_residuals(): void
    {
        self::assertSame(1,NfeTaxMath::ratio(1,1,2));
        self::assertSame(333333333333333,NfeTaxMath::ratio(999999999999999,1,3));
        self::assertSame(999999999999999,NfeTaxMath::ratio(999999999999999,80000000000000000,80000000000000000));
    }
}
