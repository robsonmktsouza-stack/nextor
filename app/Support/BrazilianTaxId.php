<?php

namespace App\Support;

/**
 * Validação dos identificadores numéricos legados utilizados no XML NFC-e.
 * O leiaute de CNPJ alfanumérico requer adaptação própria quando entrar
 * em vigência no autorizador; não converter letras em zeros.
 */
final class BrazilianTaxId
{
    public static function valid(string $value): bool
    {
        $value = preg_replace('/\D/', '', $value);
        if (strlen($value) === 11) {
            if (preg_match('/^(\d)\1{10}$/', $value)) {
                return false;
            }
            for ($i = 9; $i < 11; $i++) {
                $sum = 0;
                for ($j = 0; $j < $i; $j++) {
                    $sum += (int) $value[$j] * ($i + 1 - $j);
                }
                $check = (10 * $sum) % 11;
                if ($check === 10) {
                    $check = 0;
                }
                if ((int) $value[$i] !== $check) {
                    return false;
                }
            }
            return true;
        }

        if (strlen($value) === 14) {
            if (preg_match('/^(\d)\1{13}$/', $value)) {
                return false;
            }
            foreach ([
                [5,4,3,2,9,8,7,6,5,4,3,2],
                [6,5,4,3,2,9,8,7,6,5,4,3,2],
            ] as $index => $weights) {
                $sum = 0;
                foreach ($weights as $offset => $weight) {
                    $sum += (int) $value[$offset] * $weight;
                }
                $remaining = $sum % 11;
                $check = $remaining < 2 ? 0 : 11 - $remaining;
                if ((int) $value[12 + $index] !== $check) {
                    return false;
                }
            }
            return true;
        }

        return false;
    }
}
