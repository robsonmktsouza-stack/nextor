<?php

namespace Tests\Unit;

use App\Services\Fiscal\NfeFederalItemTaxCalculator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NfeFederalItemTaxCalculatorTest extends TestCase
{
    public function test_taxable_pis_and_cofins_are_calculated_and_serialized(): void
    {
        $result = (new NfeFederalItemTaxCalculator())->calculate([
            'pis_cst' => '01', 'pis_rate' => 1.65,
            'cofins_cst' => '01', 'cofins_rate' => 7.60,
        ], 10000);
        $this->assertSame(165, $result['pis_amount']);
        $this->assertSame(760, $result['cofins_amount']);
        $this->assertSame('1.6500', $result['pis']['pPIS']);
        $this->assertSame('7.6000', $result['cofins']['pCOFINS']);
        $this->assertSame('100.00', $result['pis']['vBC']);
    }

    public function test_unsupported_cst_is_not_treated_as_zero_tax(): void
    {
        $this->expectException(RuntimeException::class);
        (new NfeFederalItemTaxCalculator())->calculate([
            'pis_cst' => '04', 'cofins_cst' => '01', 'cofins_rate' => 7.6,
        ], 10000);
    }

    public function test_missing_taxable_rate_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        (new NfeFederalItemTaxCalculator())->calculate([
            'pis_cst' => '01', 'cofins_cst' => '01', 'cofins_rate' => 7.6,
        ], 10000);
    }

    public function test_residual_cst_with_nonzero_rate_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        (new NfeFederalItemTaxCalculator())->calculate([
            'pis_cst' => '49', 'pis_rate' => 1.65,
            'cofins_cst' => '99', 'cofins_rate' => 0,
        ], 10000);
    }
}
