<?php

namespace App\Fiscal\Tax\Support;

use App\Fiscal\Tax\Exceptions\InvalidDecimalException;

final class DecimalMath
{
    private function __construct()
    {
    }

    public static function normalize(string $value, int $maxScale, int $fixedScale): string
    {
        $value = trim($value);

        if ($value === '' || !preg_match('/^\d+(?:\.\d+)?$/', $value)) {
            throw new InvalidDecimalException("Decimal inválido: '{$value}'.");
        }

        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        if (strlen($fraction) > $maxScale) {
            throw new InvalidDecimalException(
                "Decimal '{$value}' excede a escala máxima de {$maxScale} casas."
            );
        }

        $integer = ltrim($integer, '0');
        $integer = $integer === '' ? '0' : $integer;

        $fraction = str_pad($fraction, $fixedScale, '0');

        if (strlen($fraction) > $fixedScale) {
            $discarded = substr($fraction, $fixedScale);

            if (trim($discarded, '0') !== '') {
                throw new InvalidDecimalException(
                    "Decimal '{$value}' não cabe na escala fixa de {$fixedScale} casas sem arredondamento."
                );
            }

            $fraction = substr($fraction, 0, $fixedScale);
        }

        return $fixedScale === 0 ? $integer : $integer.'.'.$fraction;
    }

    public static function add(string $left, string $right, int $scale): string
    {
        $sum = self::addIntegers(
            self::scaledInteger($left, $scale),
            self::scaledInteger($right, $scale),
        );

        return self::fromScaledInteger($sum, $scale);
    }

    public static function subtract(string $left, string $right, int $scale): string
    {
        $a = self::scaledInteger($left, $scale);
        $b = self::scaledInteger($right, $scale);

        if (self::compareIntegers($a, $b) < 0) {
            throw new InvalidDecimalException('Resultado decimal negativo não é suportado neste cenário fiscal.');
        }

        return self::fromScaledInteger(self::subtractIntegers($a, $b), $scale);
    }

    public static function multiply(
        string $left,
        int $leftScale,
        string $right,
        int $rightScale,
        int $resultScale,
    ): string {
        $product = self::multiplyIntegers(
            self::scaledInteger($left, $leftScale),
            self::scaledInteger($right, $rightScale),
        );

        $shift = $leftScale + $rightScale - $resultScale;
        $rounded = self::shiftRightHalfUp($product, $shift);

        return self::fromScaledInteger($rounded, $resultScale);
    }

    public static function percent(
        string $base,
        int $baseScale,
        string $rate,
        int $rateScale,
        int $resultScale,
    ): string {
        $product = self::multiplyIntegers(
            self::scaledInteger($base, $baseScale),
            self::scaledInteger($rate, $rateScale),
        );

        $shift = $baseScale + $rateScale + 2 - $resultScale;
        $rounded = self::shiftRightHalfUp($product, $shift);

        return self::fromScaledInteger($rounded, $resultScale);
    }

    public static function compare(string $left, string $right, int $scale): int
    {
        return self::compareIntegers(
            self::scaledInteger($left, $scale),
            self::scaledInteger($right, $scale),
        );
    }

    private static function scaledInteger(string $value, int $scale): string
    {
        $normalized = self::normalize($value, $scale, $scale);

        return ltrim(str_replace('.', '', $normalized), '0') ?: '0';
    }

    private static function fromScaledInteger(string $digits, int $scale): string
    {
        $digits = ltrim($digits, '0') ?: '0';

        if ($scale === 0) {
            return $digits;
        }

        $digits = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);
        $position = strlen($digits) - $scale;

        return substr($digits, 0, $position).'.'.substr($digits, $position);
    }

    private static function addIntegers(string $a, string $b): string
    {
        $a = strrev($a);
        $b = strrev($b);
        $length = max(strlen($a), strlen($b));
        $carry = 0;
        $result = '';

        for ($i = 0; $i < $length; $i++) {
            $sum = (int) ($a[$i] ?? '0') + (int) ($b[$i] ?? '0') + $carry;
            $result .= (string) ($sum % 10);
            $carry = intdiv($sum, 10);
        }

        if ($carry > 0) {
            $result .= (string) $carry;
        }

        return strrev($result);
    }

    private static function subtractIntegers(string $a, string $b): string
    {
        $a = strrev($a);
        $b = strrev($b);
        $borrow = 0;
        $result = '';

        for ($i = 0, $length = strlen($a); $i < $length; $i++) {
            $digit = (int) $a[$i] - (int) ($b[$i] ?? '0') - $borrow;

            if ($digit < 0) {
                $digit += 10;
                $borrow = 1;
            } else {
                $borrow = 0;
            }

            $result .= (string) $digit;
        }

        return ltrim(strrev($result), '0') ?: '0';
    }

    private static function multiplyIntegers(string $a, string $b): string
    {
        if ($a === '0' || $b === '0') {
            return '0';
        }

        $aDigits = array_reverse(array_map('intval', str_split($a)));
        $bDigits = array_reverse(array_map('intval', str_split($b)));
        $result = array_fill(0, count($aDigits) + count($bDigits), 0);

        foreach ($aDigits as $i => $da) {
            foreach ($bDigits as $j => $db) {
                $result[$i + $j] += $da * $db;
            }
        }

        for ($i = 0, $length = count($result) - 1; $i < $length; $i++) {
            $carry = intdiv($result[$i], 10);
            $result[$i] %= 10;
            $result[$i + 1] += $carry;
        }

        while (count($result) > 1 && end($result) === 0) {
            array_pop($result);
        }

        return implode('', array_reverse($result));
    }

    private static function compareIntegers(string $a, string $b): int
    {
        $a = ltrim($a, '0') ?: '0';
        $b = ltrim($b, '0') ?: '0';

        if (strlen($a) !== strlen($b)) {
            return strlen($a) <=> strlen($b);
        }

        return $a <=> $b;
    }

    private static function shiftRightHalfUp(string $digits, int $places): string
    {
        if ($places <= 0) {
            return $digits.str_repeat('0', -$places);
        }

        $digits = str_pad($digits, $places + 1, '0', STR_PAD_LEFT);
        $cut = strlen($digits) - $places;
        $kept = substr($digits, 0, $cut);
        $discarded = substr($digits, $cut);

        if ($discarded !== '' && $discarded[0] >= '5') {
            $kept = self::addIntegers($kept, '1');
        }

        return ltrim($kept, '0') ?: '0';
    }
}
