<?php

namespace App\Support;

final class Cnpj
{
    public static function digits(?string $value): string
    {
        return preg_replace('/\D/', '', (string) $value) ?? '';
    }

    public static function valid(?string $value): bool
    {
        $cnpj = self::digits($value);
        if (strlen($cnpj) !== 14 || preg_match('/^(\d)\1{13}$/', $cnpj)) {
            return false;
        }

        foreach ([12, 13] as $length) {
            $weight = $length === 12 ? 5 : 6;
            $sum = 0;
            for ($i = 0; $i < $length; $i++) {
                $sum += (int) $cnpj[$i] * $weight;
                $weight = $weight === 2 ? 9 : $weight - 1;
            }
            $mod = $sum % 11;
            $digit = $mod < 2 ? 0 : 11 - $mod;
            if ($digit !== (int) $cnpj[$length]) {
                return false;
            }
        }
        return true;
    }
}
