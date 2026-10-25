<?php

namespace Tests\Unit;

use App\Services\Fiscal\Nfe55ReturnedIpiCalculator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class Nfe55ReturnedIpiCalculatorTest extends TestCase
{
    private function parameters(): array
    {
        return ['ipi_returned_percentage' => '25.00', 'ipi_returned_original_amount' => '12.34'];
    }

    public function test_partial_return_uses_original_full_item_ipi_and_rounds_once_half_up(): void
    {
        $result = (new Nfe55ReturnedIpiCalculator())->calculate($this->parameters() + [
            'ipi_rate' => '100', 'ipi_base' => '999.99',
        ]);
        self::assertSame(309, $result['amount']);
        self::assertSame(['pDevol' => '25.00', 'vIPIDevol' => '3.09'], $result['fields']);
    }

    public function test_full_return_preserves_the_original_amount_in_cents(): void
    {
        $result = (new Nfe55ReturnedIpiCalculator())->calculate(array_replace($this->parameters(), [
            'ipi_returned_percentage' => '100',
        ]));
        self::assertSame(1234, $result['amount']);
        self::assertSame('100.00', $result['fields']['pDevol']);
    }

    public function test_return_group_is_absent_when_no_return_parameters_are_supplied(): void
    {
        self::assertNull((new Nfe55ReturnedIpiCalculator())->calculate(['ipi_rate' => '5',
            'ipi_returned_percentage_inbound' => '25', 'ipi_returned_original_amount' => null]));
    }

    public function test_return_requires_original_ipi_and_percentage_together(): void
    {
        foreach (array_keys($this->parameters()) as $missing) {
            $tax = $this->parameters();
            unset($tax[$missing]);
            $this->assertRejected($tax);
        }
    }

    public function test_percentage_respects_the_schema_two_decimal_domain_and_original_value_is_never_negative(): void
    {
        foreach ([['ipi_returned_percentage' => '0'], ['ipi_returned_percentage' => '-1'],
            ['ipi_returned_percentage' => '100.01'], ['ipi_returned_percentage' => '25.001'],
            ['ipi_returned_original_amount' => '-1'], ['ipi_returned_original_amount' => '1.001'],
            ['ipi_returned_original_amount' => '10000000000000.00']] as $invalid) {
            $this->assertRejected(array_replace($this->parameters(), $invalid));
        }
    }

    public function test_a_supplied_return_value_cannot_override_the_calculated_value(): void
    {
        $this->assertRejected($this->parameters() + ['ipi_returned_amount' => '999.99']);
    }

    public function test_smallest_positive_percentage_and_zero_original_ipi_are_explicit_values(): void
    {
        $result = (new Nfe55ReturnedIpiCalculator())->calculate([
            'ipi_returned_percentage' => '0.01', 'ipi_returned_original_amount' => '0.00',
        ]);
        self::assertSame(0, $result['amount']);
        self::assertSame('0.01', $result['fields']['pDevol']);
    }

    private function assertRejected(array $tax): void
    {
        try {
            (new Nfe55ReturnedIpiCalculator())->calculate($tax);
            self::fail('IPI devolvido inválido aceito.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('IPI', $error->getMessage());
        }
    }
}
