<?php

namespace Tests\Unit;

use App\Support\BrazilianTaxId;
use PHPUnit\Framework\TestCase;

final class BrazilianTaxIdTest extends TestCase
{
    public function test_valid_cpf_and_cnpj_check_digits(): void
    {
        self::assertTrue(BrazilianTaxId::valid('529.982.247-25'));
        self::assertTrue(BrazilianTaxId::valid('11.222.333/0001-81'));
        self::assertTrue(BrazilianTaxId::valid('52998224725'));
        self::assertFalse(BrazilianTaxId::valid('52998224726'));
        self::assertFalse(BrazilianTaxId::valid('11222333000182'));
    }

    public function test_repeated_numbers_and_incomplete_documents_are_rejected(): void
    {
        foreach (['', '00000000000', '11111111111', '00000000000000', '123',
            '12345678901', '12345678000199', 'ABC00181'] as $invalid) {
            self::assertFalse(BrazilianTaxId::valid($invalid), $invalid);
        }
    }
}
