<?php

namespace Tests\Unit;

use App\Services\Fiscal\FiscalAdValoremCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class FiscalAdValoremCalculatorTest extends TestCase
{
    public function test_calculates_with_integer_cents_and_rounds_half_up(): void
    {
        $result=(new FiscalAdValoremCalculator())->calculate(10001,'1.6500');
        $this->assertSame(165,$result['amount_cents']);
    }

    public function test_fractional_cent_rounds_up_at_half(): void
    {
        $result=(new FiscalAdValoremCalculator())->calculate(1,'50');
        $this->assertSame(1,$result['amount_cents']);
    }

    public function test_formats_cents(): void
    {
        $this->assertSame('123.05',(new FiscalAdValoremCalculator())->formatCents(12305));
    }

    public function test_rejects_invalid_percentage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new FiscalAdValoremCalculator())->calculate(100,'101');
    }
}
