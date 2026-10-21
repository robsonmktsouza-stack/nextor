<?php

namespace App\Support;

use InvalidArgumentException;

final class InvoiceMoney
{
    public static function cents(string $amount): int
    {
        if (!preg_match('/^([0-9]{1,10})(?:\.([0-9]{1,2}))?$/', $amount, $matches)) {
            throw new InvalidArgumentException('Valor monetário inválido.');
        }
        $cents = ((int) $matches[1]) * 100
            + (int) str_pad($matches[2] ?? '', 2, '0');
        if ($cents > 999999999999) {
            throw new InvalidArgumentException('Valor excede o limite permitido.');
        }
        return $cents;
    }

    public static function decimal(int $cents): string
    {
        if ($cents < 0 || $cents > 999999999999) {
            throw new InvalidArgumentException('Valor monetário fora do intervalo.');
        }
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    public static function brl(int $cents): string
    {
        return 'R$ '.number_format($cents / 100, 2, ',', '.');
    }
}
