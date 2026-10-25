<?php

namespace App\Services\Fiscal;

use RuntimeException;

/** Decimal arithmetic for NF-e. Inputs are explicit decimal values, never rates inferred from codes. */
final class NfeTaxMath
{
    public static function scaled(mixed $value, int $scale, string $label = 'Valor'): int
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw new RuntimeException($label.': valor decimal ausente ou inválido.');
        }
        $text = trim((string) $value);
        if (!preg_match('/^\d+(?:\.\d+)?$/D', $text)) {
            throw new RuntimeException($label.': use um valor decimal não negativo.');
        }
        [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '');
        if (strlen(rtrim($fraction, '0')) > $scale) {
            throw new RuntimeException($label.': precisão decimal superior à permitida.');
        }
        $digits = ltrim($whole.str_pad(substr($fraction, 0, $scale), $scale, '0'), '0') ?: '0';
        if (strlen($digits) > strlen((string) PHP_INT_MAX)
            || (strlen($digits) === strlen((string) PHP_INT_MAX) && strcmp($digits, (string) PHP_INT_MAX) > 0)) {
            throw new RuntimeException($label.': valor excede o limite de cálculo.');
        }
        return (int) $digits;
    }

    public static function format(int $value, int $scale = 2): string
    {
        if ($value < 0) throw new RuntimeException('Valor fiscal negativo.');
        $digits = str_pad((string) $value, $scale + 1, '0', STR_PAD_LEFT);
        return substr($digits, 0, -$scale).'.'.substr($digits, -$scale);
    }

    public static function rate(mixed $value, string $label): int
    {
        $rate = self::scaled($value, 4, $label);
        if ($rate > 1000000) throw new RuntimeException($label.': percentual superior a 100%.');
        return $rate;
    }

    public static function percent(int $baseCents, mixed $rate, string $label): int
    {
        return self::product($baseCents, self::rate($rate, $label), 6);
    }

    public static function quantity(mixed $quantity, mixed $unitRate, string $label): int
    {
        return self::product(self::scaled($quantity, 4, $label.' quantidade'),
            self::scaled($unitRate, 4, $label.' valor por unidade'), 6);
    }

    /** Half-up multiplication divided by 10^scale, without overflowing intermediate native integers. */
    public static function product(int $left, int $right, int $scale): int
    {
        if ($left < 0 || $right < 0 || $scale < 0) throw new RuntimeException('Cálculo fiscal inválido.');
        $text = self::multiplyDigits($left,$right);
        if ($scale > 0) {
            $text = str_pad($text, $scale + 1, '0', STR_PAD_LEFT);
            $round = (int) $text[strlen($text) - $scale] >= 5;
            $text = substr($text, 0, -$scale);
        } else $round = false;
        $amount = self::scaled($text, 0, 'Resultado fiscal');
        if ($round && $amount === PHP_INT_MAX) throw new RuntimeException('Resultado fiscal excede o limite.');
        return $amount + (int) $round;
    }

    public static function ratio(int $left,int $right,int $denominator): int
    {
        if ($left < 0 || $right < 0 || $denominator <= 0 || $denominator > intdiv(PHP_INT_MAX,10)) {
            throw new RuntimeException('Rateio fiscal excede o limite de cálculo.');
        }
        $text=self::multiplyDigits($left,$right);$remainder=0;$quotient='';
        for ($i=0;$i<strlen($text);$i++) {
            $current=$remainder*10+(int)$text[$i];
            $quotient.=(string)intdiv($current,$denominator);
            $remainder=$current%$denominator;
        }
        $amount=self::scaled(ltrim($quotient,'0')?:'0',0,'Rateio fiscal');
        $round=$remainder >= intdiv($denominator,2)+($denominator%2);
        if ($round && $amount === PHP_INT_MAX) throw new RuntimeException('Rateio fiscal excede o limite.');
        return $amount+(int)$round;
    }

    public static function line(mixed $quantity,mixed $dimension,mixed $price): array
    {
        $units=self::product(self::scaled($quantity,4,'Quantidade'),self::scaled($dimension,4,'Fator dimensional'),4);
        $gross=self::product($units,self::scaled($price,4,'Preço unitário'),6);
        if ($units > 999999999999999 || $gross > 999999999999999) {
            throw new RuntimeException('Quantidade ou valor do item excede o limite do documento fiscal.');
        }
        return ['quantity_units'=>$units,'gross_cents'=>$gross];
    }

    private static function multiplyDigits(int $left,int $right): string
    {
        $a = (string) $left; $b = (string) $right;
        $digits = array_fill(0, strlen($a) + strlen($b), 0);
        for ($i = strlen($a) - 1; $i >= 0; $i--) {
            for ($j = strlen($b) - 1; $j >= 0; $j--) {
                $digits[$i + $j + 1] += (int) $a[$i] * (int) $b[$j];
            }
        }
        for ($i = count($digits) - 1; $i > 0; $i--) {
            $digits[$i - 1] += intdiv($digits[$i], 10);
            $digits[$i] %= 10;
        }
        return ltrim(implode('', $digits), '0') ?: '0';
    }
}
