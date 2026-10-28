<?php

namespace Tests\Unit;

use App\Services\Fiscal\Nfe55FederalStCalculator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class Nfe55FederalStCalculatorTest extends TestCase
{
    private function percent(): array
    {
        return ['pis_st_calculation_type' => 'percent', 'pis_st_base' => '123.45',
            'pis_st_rate' => '1.6500', 'pis_st_add_to_total' => '0'];
    }

    public function test_st_uses_its_explicit_base_and_does_not_inherit_the_ordinary_tax(): void
    {
        $result = (new Nfe55FederalStCalculator())->calculate($this->percent() + [
            'pis_base' => '999.99', 'pis_rate' => '100',
            'cofins_st_calculation_type' => 'percent', 'cofins_st_base' => '82.00',
            'cofins_st_rate' => '7.6000', 'cofins_st_add_to_total' => 1,
        ]);
        self::assertSame(204, $result['pis_st_amount']);
        self::assertSame(623, $result['cofins_st_amount']);
        self::assertSame('123.45', $result['pis_st']['vBC']);
        self::assertSame('1.6500', $result['pis_st']['pPIS']);
        self::assertSame('0', $result['pis_st']['indSomaPISST']);
        self::assertSame('1', $result['cofins_st']['indSomaCOFINSST']);
        self::assertSame(623, $result['total_addition']);
        self::assertArrayNotHasKey('CST', $result['pis_st']);
    }

    public function test_quantity_st_rounds_half_up_per_item_and_only_serializes_the_quantity_choice(): void
    {
        $result = (new Nfe55FederalStCalculator())->calculateGroup([
            'cofins_st_calculation_type' => 'quantity', 'cofins_st_quantity' => '2.0000',
            'cofins_st_unit_rate' => '0.0025', 'cofins_st_add_to_total' => '1',
        ], 'cofins');
        self::assertSame(1, $result['amount']);
        self::assertSame(1, $result['total_addition']);
        self::assertSame(['qBCProd' => '2.0000', 'vAliqProd' => '0.0025',
            'vCOFINS' => '0.01', 'indSomaCOFINSST' => '1'], $result['fields']);
    }

    public function test_an_inactive_legacy_profile_is_not_interpreted_as_tax_incidence(): void
    {
        $result = (new Nfe55FederalStCalculator())->calculate([
            'pis_st_calc_type' => 'none', 'pis_st_rate' => '0', 'cofins_st_rate' => '0.0000',
            'pis_st_rate_inbound' => '2',
        ]);
        self::assertSame(['pis_st' => [], 'cofins_st' => [], 'pis_st_amount' => 0,
            'cofins_st_amount' => 0, 'total_addition' => 0], $result);
    }

    public function test_legacy_percentage_mode_is_supported_when_the_remaining_inputs_are_explicit(): void
    {
        $tax = $this->percent();
        unset($tax['pis_st_calculation_type']);
        $tax['pis_st_calc_type'] = 'percentage';
        self::assertSame(204, (new Nfe55FederalStCalculator())->calculateGroup($tax, 'pis')['amount']);
    }

    public function test_active_st_needs_an_explicit_base_rate_and_total_indicator_even_when_tax_is_zero(): void
    {
        foreach (['pis_st_base', 'pis_st_rate', 'pis_st_add_to_total'] as $missing) {
            $tax = $this->percent();
            $tax['pis_st_rate'] = '0';
            unset($tax[$missing]);
            $this->assertRejected($tax);
        }
        $result = (new Nfe55FederalStCalculator())->calculateGroup(array_replace($this->percent(), ['pis_st_rate' => '0']), 'pis');
        self::assertSame(0, $result['amount']);
        self::assertSame('123.45', $result['fields']['vBC']);
    }

    public function test_combined_quantity_and_percent_parameters_are_rejected_even_if_one_is_zero(): void
    {
        $this->assertRejected($this->percent() + ['pis_st_quantity' => '0']);
        $this->assertRejected(['pis_st_calculation_type' => 'quantity', 'pis_st_quantity' => '2',
            'pis_st_unit_rate' => '1', 'pis_st_rate' => '0', 'pis_st_add_to_total' => '0']);
    }

    public function test_invalid_or_unimplemented_st_parameters_are_not_silently_dropped(): void
    {
        foreach ([['pis_st_cst' => '00'], ['pis_st_amount' => '0'],
            ['pis_st_calc_type' => 'quantity'], ['pis_st_add_to_total' => true],
            ['pis_st_add_to_total' => '2'], ['pis_st_rate' => '100.0001'],
            ['pis_st_base' => '-1'], ['pis_st_rate' => '1.23456'],
            ['pis_st_base' => '10000000000000.00']] as $invalid) {
            $this->assertRejected(array_replace($this->percent(), $invalid));
        }
    }

    public function test_quantity_st_requires_a_positive_quantity_and_an_explicit_unit_rate(): void
    {
        foreach ([['pis_st_quantity' => '0'], ['pis_st_quantity' => '-1'],
            ['pis_st_quantity' => '1.23456'], ['pis_st_quantity' => '1000000000000'],
            ['pis_st_unit_rate' => null], ['pis_st_unit_rate' => '100000000000']] as $invalid) {
            $this->assertRejected(array_replace(['pis_st_calculation_type' => 'quantity',
                'pis_st_quantity' => '2', 'pis_st_unit_rate' => '1', 'pis_st_add_to_total' => '0'], $invalid));
        }
    }

    public function test_nonzero_st_without_an_explicit_mode_cannot_be_treated_as_inactive(): void
    {
        $this->assertRejected(['pis_st_rate' => '1']);
        $this->assertRejected(['pis_st_calc_type' => 'none', 'pis_st_add_to_total' => '1']);
    }

    private function assertRejected(array $tax): void
    {
        try {
            (new Nfe55FederalStCalculator())->calculateGroup($tax, 'pis');
            self::fail('Parâmetro ST inválido aceito.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('PIS ST', $error->getMessage());
        }
    }
}
