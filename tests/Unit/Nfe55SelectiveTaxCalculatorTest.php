<?php

namespace Tests\Unit;

use App\Services\Fiscal\Nfe55SelectiveTaxCalculator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class Nfe55SelectiveTaxCalculatorTest extends TestCase
{
    /** Hypothetical reviewed fixture; these rates and codes do not claim a legal classification. */
    private function parameters(): array
    {
        return ['is_cst' => '123', 'is_class' => '654321', 'is_calculation_type' => 'percent',
            'is_base' => '123.45', 'is_rate' => '5.0000',
            'is_valid_from' => '2027-01-01', 'is_valid_until' => '2027-12-31',
            'is_legal_source' => 'https://www.planalto.gov.br/fixture-is-2027',
            'is_legal_reference' => 'Regra hipotética revisada para o teste de cálculo.'];
    }

    public function test_percent_calculation_serializes_tis_and_preserves_explicit_legal_metadata(): void
    {
        $result = (new Nfe55SelectiveTaxCalculator())->calculate($this->parameters(), '2027-01-01');
        self::assertSame(617, $result['amount']);
        self::assertSame(['CSTIS' => '123', 'cClassTribIS' => '654321',
            'vBCIS' => '123.45', 'pIS' => '5.0000', 'vIS' => '6.17'], $result['fields']);
        self::assertFalse($result['metadata']['classification_inferred']);
        self::assertFalse($result['metadata']['classification_catalog_verified']);
        self::assertSame($this->parameters()['is_legal_source'], $result['metadata']['legal_source']);
    }

    public function test_quantity_mode_keeps_the_required_explicit_base_and_zero_percent_fields(): void
    {
        $result = (new Nfe55SelectiveTaxCalculator())->calculate(array_replace($this->parameters(), [
            'is_calculation_type' => 'quantity', 'is_base' => '0', 'is_rate' => '0',
            'is_unit_rate' => '1.2345', 'is_quantity' => '3.5000', 'is_unit' => 'KG',
        ]), '2027-01-01');
        self::assertSame(432, $result['amount']);
        self::assertSame('0.00', $result['fields']['vBCIS']);
        self::assertSame('0.0000', $result['fields']['pIS']);
        self::assertSame('1.2345', $result['fields']['adRemIS']);
        self::assertSame('3.5000', $result['fields']['qTrib']);
        self::assertSame('KG', $result['fields']['uTrib']);
    }

    public function test_combined_tax_rounds_the_sum_once_instead_of_rounding_two_half_cents_up(): void
    {
        $result = (new Nfe55SelectiveTaxCalculator())->calculate(array_replace($this->parameters(), [
            'is_calculation_type' => 'combined', 'is_base' => '1.00', 'is_rate' => '0.5000',
            'is_unit_rate' => '0.0025', 'is_quantity' => '2.0000', 'is_unit' => 'UN',
        ]), '2027-01-01');
        self::assertSame(1, $result['amount']);
        self::assertSame('0.01', $result['fields']['vIS']);
    }

    public function test_an_absent_or_legacy_zero_rate_profile_does_not_require_is_in_2026_or_future_years(): void
    {
        $calculator = new Nfe55SelectiveTaxCalculator();
        self::assertNull($calculator->calculate([], '2026-10-25'));
        self::assertNull($calculator->calculate(['is_rate' => '0.0000'], '2026-10-25'));
        self::assertNull($calculator->calculate([], '2027-01-01'));
    }

    public function test_an_explicit_is_group_is_not_applied_before_2027_even_when_its_amount_is_zero(): void
    {
        $this->assertRejected(array_replace($this->parameters(), ['is_rate' => '0']), '2026-12-31');
    }

    public function test_future_parameters_require_both_legal_dates_source_and_reference(): void
    {
        foreach (['is_valid_from', 'is_valid_until', 'is_legal_source', 'is_legal_reference'] as $missing) {
            $tax = $this->parameters();
            unset($tax[$missing]);
            $this->assertRejected($tax);
        }
        $this->assertRejected(array_replace($this->parameters(), ['is_legal_source' => 'lei sem endereço de origem']));
    }

    public function test_validity_is_bounded_and_does_not_prolong_a_reviewed_rule(): void
    {
        $this->assertRejected($this->parameters(), '2028-01-01');
        $this->assertRejected(array_replace($this->parameters(), ['is_valid_from' => '2027-02-01']));
        $this->assertRejected(array_replace($this->parameters(), ['is_valid_from' => '2026-01-01']));
        $this->assertRejected(array_replace($this->parameters(), ['is_valid_until' => '2026-12-31']));
        $this->assertRejected(array_replace($this->parameters(), ['is_valid_until' => '2027-02-30']));
        self::assertSame(617, (new Nfe55SelectiveTaxCalculator())->calculate($this->parameters(), '2027-12-31')['amount']);
    }

    public function test_no_cst_class_or_calculation_mode_is_inferred_from_other_inputs(): void
    {
        foreach (['is_cst', 'is_class', 'is_calculation_type', 'is_base', 'is_rate'] as $missing) {
            $tax = $this->parameters();
            unset($tax[$missing]);
            $this->assertRejected($tax);
        }
        foreach ([['is_cst' => '12'], ['is_class' => '12345'], ['is_calculation_type' => 'none']] as $invalid) {
            $this->assertRejected(array_replace($this->parameters(), $invalid));
        }
    }

    public function test_percent_and_specific_rates_respect_the_rtc_schema_instead_of_a_fabricated_rate_table(): void
    {
        $result = (new Nfe55SelectiveTaxCalculator())->calculate(array_replace($this->parameters(), [
            'is_base' => '1.00', 'is_rate' => '150.0000',
        ]), '2027-01-01');
        self::assertSame(150, $result['amount']);
        foreach (['1000', '1.23456', '-1'] as $rate) {
            $this->assertRejected(array_replace($this->parameters(), ['is_rate' => $rate]));
            $this->assertRejected(array_replace($this->parameters(), ['is_calculation_type' => 'combined',
                'is_unit_rate' => $rate, 'is_quantity' => '1', 'is_unit' => 'UN']));
        }
    }

    public function test_quantity_mode_rejects_a_nonzero_percent_and_incomplete_or_invalid_unit_parameters(): void
    {
        $tax = array_replace($this->parameters(), ['is_calculation_type' => 'quantity', 'is_rate' => '0',
            'is_unit_rate' => '1', 'is_quantity' => '1', 'is_unit' => 'UN']);
        foreach (['is_base', 'is_rate', 'is_unit_rate', 'is_quantity', 'is_unit'] as $missing) {
            $incomplete = $tax;
            unset($incomplete[$missing]);
            $this->assertRejected($incomplete);
        }
        foreach ([['is_rate' => '1'], ['is_quantity' => '0'], ['is_quantity' => '100000000000'],
            ['is_quantity' => '0.00001'], ['is_unit' => 'UNIDADE'], ['is_unit' => "UN\n"]] as $invalid) {
            $this->assertRejected(array_replace($tax, $invalid));
        }
    }

    public function test_percent_mode_and_unknown_supplied_fields_cannot_silently_discard_fiscal_data(): void
    {
        foreach ([['is_unit_rate' => '0'], ['is_quantity' => '0'], ['is_unit' => 'UN'],
            ['is_amount' => '0']] as $extra) {
            $this->assertRejected($this->parameters() + $extra);
        }
    }

    public function test_money_and_combined_results_cannot_exceed_the_document_limit(): void
    {
        $this->assertRejected(array_replace($this->parameters(), ['is_base' => '10000000000000.00']));
        $this->assertRejected(array_replace($this->parameters(), ['is_base' => '9999999999999.99', 'is_rate' => '101']));
        $result = (new Nfe55SelectiveTaxCalculator())->calculate(array_replace($this->parameters(), [
            'is_base' => '9999999999999.99', 'is_rate' => '100',
        ]), '2027-01-01');
        self::assertSame(999999999999999, $result['amount']);
    }

    private function assertRejected(array $tax, string $issueDate = '2027-01-01'): void
    {
        try {
            (new Nfe55SelectiveTaxCalculator())->calculate($tax, $issueDate);
            self::fail('Parâmetro inválido do Imposto Seletivo aceito.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('Imposto Seletivo', $error->getMessage());
        }
    }
}
