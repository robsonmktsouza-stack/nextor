<?php

namespace Tests\Unit;

use App\Services\Fiscal\NFCeItemValueGuard;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NFCeItemValueGuardTest extends TestCase
{
    public function test_values_matching_acbr_xml_rounding_are_accepted(): void
    {
        (new NFCeItemValueGuard())->verify([
            'quantity'=>'1.250',
            'unit_price'=>'10.0000',
            'discount'=>'2.50',
            'line_total'=>'10.00',
        ]);
        self::assertTrue(true);
    }

    public function test_quantity_with_more_than_three_decimal_places_fails(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('quantity');
        (new NFCeItemValueGuard())->verify([
            'quantity'=>'1.0001', 'unit_price'=>'1.00', 'discount'=>'0.00',
        ]);
    }

    public function test_unit_price_exceeding_four_decimal_places_fails(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unit_price');
        (new NFCeItemValueGuard())->verify([
            'quantity'=>'1.000', 'unit_price'=>'10.12345', 'discount'=>'0.00',
        ]);
    }

    public function test_incorrect_line_total_cannot_be_signed(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('diverge');
        (new NFCeItemValueGuard())->verify([
            'quantity'=>'2.000', 'unit_price'=>'7.00', 'discount'=>'1.00',
            'line_total'=>'14.00',
        ]);
    }

    public function test_valid_gs1_gtin_and_sem_gtin_are_allowed(): void
    {
        $guard=new NFCeItemValueGuard();
        $guard->verifyGtin('4006381333931');
        $guard->verifyGtin('SEM GTIN');
        $guard->verifyGtin(null);
        self::assertTrue(true);
    }

    public function test_gtin_with_invalid_check_digit_fails_before_xml(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Dígito verificador');
        (new NFCeItemValueGuard())->verifyGtin('4006381333932');
    }

    public function test_gtin_with_letters_is_not_silently_sanitized(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sem letras');
        (new NFCeItemValueGuard())->verifyGtin('400638133393A');
    }
}
