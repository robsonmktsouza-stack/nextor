<?php

namespace Tests\Unit;

use App\Services\Fiscal\NfeIcmsItemTaxCalculator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NfeIcmsResidualTaxCalculatorTest extends TestCase
{
    private function own(string $code = '90'): array
    {
        return [$code === '900' ? 'icms_csosn' : 'icms_cst'=>$code,
            'icms_base'=>'100.00', 'icms_rate'=>'18', 'icms_base_mode'=>'3'];
    }

    private function st(): array
    {
        return ['icms_st_base_mode'=>'4', 'icms_st_base'=>'100', 'icms_st_mva'=>'40',
            'icms_st_rate'=>'18', 'icms_st_deduction'=>'18', 'fcp_st_rate'=>'2'];
    }

    public function test_cst_90_own_reduction_and_fcp_use_only_explicit_parameters(): void
    {
        $result = $this->calculate($this->own() + ['icms_base_reduction'=>'20', 'fcp_rate'=>'2', 'fcp_base'=>'80']);
        self::assertSame('90', $result['fields']['CST']);
        self::assertSame('3', $result['fields']['modBC']);
        self::assertSame('20.0000', $result['fields']['pRedBC']);
        self::assertSame(8000, $result['base_cents']);
        self::assertSame(1440, $result['icms_amount']);
        self::assertSame(160, $result['fcp_amount']);
        self::assertSame(0, $result['st_amount']);
        self::assertSame(0, $result['total_addition']);
        self::assertArrayNotHasKey('modBCST', $result['fields']);
    }

    public function test_st_only_does_not_create_an_own_tax_group_for_cst_90_or_csosn_900(): void
    {
        $normal = $this->calculate(['icms_cst'=>'90', 'icms_st_base_mode'=>'6',
            'icms_st_base'=>'140', 'icms_st_rate'=>'18', 'icms_st_deduction'=>'0', 'fcp_st_rate'=>'2']);
        self::assertSame(0, $normal['icms_amount']);
        self::assertSame(2520, $normal['st_amount']);
        self::assertSame(280, $normal['fcp_st_amount']);
        self::assertSame(2800, $normal['total_addition']);
        self::assertArrayNotHasKey('vBC', $normal['fields']);
        self::assertArrayNotHasKey('pICMS', $normal['fields']);
        $simple = $this->calculate(['icms_csosn'=>'900'] + $this->st(), '1');
        self::assertSame(0, $simple['icms_amount']);
        self::assertSame(720, $simple['st_amount']);
        self::assertArrayNotHasKey('modBC', $simple['fields']);
    }

    public function test_cst_90_can_combine_own_tax_st_and_both_fcp_groups_without_adding_own_tax_again(): void
    {
        $result = $this->calculate($this->own() + $this->st() + ['fcp_rate'=>'2']);
        self::assertSame(1800, $result['icms_amount']);
        self::assertSame(200, $result['fcp_amount']);
        self::assertSame(720, $result['st_amount']);
        self::assertSame(280, $result['fcp_st_amount']);
        self::assertSame(1000, $result['total_addition']);
        self::assertSame('100.00', $result['fields']['vBCFCP']);
        self::assertSame('140.00', $result['fields']['vBCFCPST']);
    }

    public function test_csosn_900_supports_explicit_own_st_and_credit_groups(): void
    {
        $result = $this->calculate($this->own('900') + $this->st() + ['icms_credit_rate'=>'3.1234'], '1');
        self::assertSame('900', $result['fields']['CSOSN']);
        self::assertSame(1800, $result['icms_amount']);
        self::assertSame(720, $result['st_amount']);
        self::assertSame(312, $result['credit_amount']);
        self::assertSame('3.1234', $result['fields']['pCredSN']);
        self::assertSame('3.12', $result['fields']['vCredICMSSN']);
        self::assertSame(1000, $result['total_addition']);
        self::assertArrayNotHasKey('vFCP', $result['fields']);
    }

    public function test_credit_only_900_uses_its_explicit_base_without_fabricating_own_or_st_groups(): void
    {
        $result = $this->calculate(['icms_csosn'=>'900', 'icms_credit_rate'=>'3.1234', 'icms_base'=>'50.00'], '1');
        self::assertSame(156, $result['credit_amount']);
        self::assertSame(0, $result['icms_amount']);
        self::assertSame(0, $result['st_amount']);
        self::assertSame(['CSOSN'=>'900', 'pCredSN'=>'3.1234', 'vCredICMSSN'=>'1.56'], $result['fields']);
    }

    public function test_residual_codes_never_supply_an_implicit_zero_group_or_default_base_mode_or_rate(): void
    {
        foreach (['90','900'] as $code) {
            $crt = $code === '900' ? '1' : '3';
            $this->assertRejected([$code === '900' ? 'icms_csosn' : 'icms_cst'=>$code], $crt);
            foreach (['icms_base','icms_rate','icms_base_mode'] as $missing) {
                $tax = $this->own($code);
                unset($tax[$missing]);
                $this->assertRejected($tax, $crt);
            }
        }
        $this->assertRejected(['icms_csosn'=>'900', 'icms_rate'=>'0'], '1');
    }

    public function test_zero_own_rate_is_accepted_only_as_an_explicit_complete_group(): void
    {
        $result = $this->calculate(array_replace($this->own('900'), ['icms_rate'=>'0']), '1');
        self::assertSame('100.00', $result['fields']['vBC']);
        self::assertSame('0.0000', $result['fields']['pICMS']);
        self::assertSame('0.00', $result['fields']['vICMS']);
        self::assertSame(0, $result['credit_amount']);
        self::assertArrayNotHasKey('pCredSN', $result['fields']);
    }

    public function test_900_credit_needs_a_base_and_disallowed_fcp_credit_mei_or_contradictory_st_inputs_fail(): void
    {
        $this->assertRejected(['icms_csosn'=>'900', 'icms_credit_rate'=>'3'], '1');
        $this->assertRejected($this->own() + ['icms_credit_rate'=>'0']);
        $this->assertRejected($this->own('900') + ['fcp_rate'=>'0'], '1');
        $this->assertRejected($this->own('900'), '4');
        $this->assertRejected(array_replace($this->own('900') + $this->st(), ['icms_st_deduction'=>'0']), '1');
        $this->assertRejected(array_replace($this->own(), ['icms_base'=>'10000000000000.00']));
    }

    public function test_cst_90_relief_uses_only_its_official_motives_and_an_explicit_total_deduction_decision(): void
    {
        $tax = ['icms_cst'=>'90', 'icms_desoneration_amount'=>'18',
            'icms_desoneration_reason'=>'9', 'icms_desoneration_deduct'=>'1'];
        $result = $this->calculate($tax);
        self::assertSame(1800, $result['desonerated_amount']);
        self::assertSame(1800, $result['total_deduction']);
        self::assertSame('9', $result['fields']['motDesICMS']);
        self::assertArrayNotHasKey('vBC', $result['fields']);
        $this->assertRejected(array_replace($tax, ['icms_desoneration_reason'=>'1']));
        $this->assertRejected(array_replace($tax, ['icms_desoneration_deduct'=>null]));
        $this->assertRejected(array_replace($tax, ['icms_desoneration_amount'=>'100.01']));
        $this->assertRejected($this->own() + ['icms_st_desoneration_amount'=>'0']);
    }

    public function test_optional_positive_decimal_fields_are_omitted_when_their_configured_value_is_zero(): void
    {
        $result = $this->calculate($this->own() + [
            'icms_base_reduction'=>'0', 'fcp_rate'=>'0', 'icms_st_base_mode'=>'4',
            'icms_st_base'=>'100', 'icms_st_mva'=>'0', 'icms_st_base_reduction'=>'0',
            'icms_st_rate'=>'18', 'icms_st_deduction'=>'18', 'fcp_st_rate'=>'0',
        ]);
        self::assertSame(1800, $result['icms_amount']);
        self::assertSame(0, $result['st_amount']);
        foreach (['pRedBC','pMVAST','pRedBCST','pFCP','vFCP','pFCPST','vFCPST'] as $field) self::assertArrayNotHasKey($field, $result['fields']);
    }

    private function calculate(array $tax, string $crt = '3'): array
    {
        return (new NfeIcmsItemTaxCalculator())->calculate($tax, 10000, $crt);
    }

    private function assertRejected(array $tax, string $crt = '3'): void
    {
        try {
            $this->calculate($tax, $crt);
            self::fail('ICMS residual incompleto ou incompatível aceito.');
        } catch (RuntimeException $error) { self::assertNotSame('', $error->getMessage()); }
    }
}
