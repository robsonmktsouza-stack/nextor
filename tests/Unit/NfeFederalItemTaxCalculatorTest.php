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
            'pis_cst' => '60', 'cofins_cst' => '01', 'cofins_rate' => 7.6,
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

    public function test_non_taxed_contributions_do_not_emit_a_fabricated_taxable_base(): void
    {
        $result = (new NfeFederalItemTaxCalculator())->calculate([
            'pis_cst'=>'04','cofins_cst'=>'09','ipi_cst'=>'52','ipi_enq'=>'301',
        ], 10000);
        self::assertSame(['CST'=>'04'], $result['pis']);
        self::assertSame(['CST'=>'09'], $result['cofins']);
        self::assertSame(0, $result['ipi_amount']);
        self::assertArrayNotHasKey('vBC', $result['ipi']);
    }

    public function test_quantity_contributions_and_ipi_round_each_item_half_up(): void
    {
        $result = (new NfeFederalItemTaxCalculator())->calculate([
            'pis_cst'=>'03','pis_quantity'=>'3.5000','pis_unit_rate'=>'1.2345',
            'cofins_cst'=>'03','cofins_quantity'=>'2.0000','cofins_unit_rate'=>'0.0025',
            'ipi_cst'=>'50','ipi_enq'=>'999','ipi_quantity'=>'3.5000','ipi_unit_rate'=>'1.2345',
        ],10000);
        self::assertSame(432,$result['pis_amount']);
        self::assertSame(1,$result['cofins_amount']);
        self::assertSame(432,$result['ipi_amount']);
        self::assertSame('3.5000',$result['ipi']['qUnid']);
        self::assertArrayNotHasKey('vBC',$result['ipi']);
    }

    public function test_taxable_ipi_uses_explicit_rate_and_legal_enquadramento(): void
    {
        $result=(new NfeFederalItemTaxCalculator())->calculate([
            'pis_cst'=>'01','pis_rate'=>'1.6500','cofins_cst'=>'01','cofins_rate'=>'7.6000',
            'ipi_cst'=>'50','ipi_enq'=>'999','ipi_rate'=>'5.0000',
        ],10000);
        self::assertSame(500,$result['ipi_amount']);
        self::assertSame('100.00',$result['ipi']['vBC']);
        self::assertSame('999',$result['ipi']['cEnq']);
    }

    public function test_non_taxed_code_with_a_tax_rate_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        (new NfeFederalItemTaxCalculator())->calculate([
            'pis_cst'=>'04','pis_rate'=>'1.65','cofins_cst'=>'09',
        ],10000);
    }

    public function test_taxable_ipi_without_enquadramento_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        (new NfeFederalItemTaxCalculator())->calculate([
            'pis_cst'=>'49','pis_rate'=>'0','cofins_cst'=>'49','cofins_rate'=>'0','ipi_cst'=>'50','ipi_rate'=>'5',
        ],10000);
    }

    public function test_legal_base_can_be_explicit_without_changing_the_product_value(): void
    {
        $result=(new NfeFederalItemTaxCalculator())->calculate([
            'pis_cst'=>'01','pis_rate'=>'1.65','pis_base'=>'82.00',
            'cofins_cst'=>'01','cofins_rate'=>'7.6','cofins_base'=>'82.00',
        ],10000);
        self::assertSame('82.00',$result['pis']['vBC']);
        self::assertSame(135,$result['pis_amount']);
        self::assertSame(623,$result['cofins_amount']);
    }

    public function test_ipi_legal_enquadramento_must_match_exemption_immunity_or_suspension(): void
    {
        foreach ([['52','999'],['54','301'],['55','001'],['50','301']] as [$cst,$enq]) {
            try {
                (new NfeFederalItemTaxCalculator())->calculateGroup(['ipi_cst'=>$cst,'ipi_enq'=>$enq,'ipi_rate'=>'0'],'ipi',10000);
                self::fail('Contradictory legal enquadramento accepted.');
            } catch (RuntimeException $error) { self::assertStringContainsString('enquadramento',$error->getMessage()); }
        }
    }

    public function test_residual_contribution_can_use_an_explicit_quantity_calculation(): void
    {
        $result=(new NfeFederalItemTaxCalculator())->calculateGroup([
            'pis_cst'=>'99','pis_calculation_type'=>'quantity','pis_quantity'=>'2.5','pis_unit_rate'=>'1.23',
        ],'pis',10000);
        self::assertSame(308,$result['amount']);
        self::assertSame('2.5000',$result['fields']['qBCProd']);
        self::assertArrayNotHasKey('vBC',$result['fields']);
    }
}
