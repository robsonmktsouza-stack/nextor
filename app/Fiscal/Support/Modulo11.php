<?php

namespace App\Fiscal\Support;

use App\Fiscal\Exceptions\InvalidFiscalDataException;

final class Modulo11
{
    private function __construct()
    {
    }

    /**
     * DV da chave de acesso.
     *
     * Para caracteres alfanuméricos, usa o valor ASCII - 48. Para os dígitos
     * 0-9 o resultado permanece exatamente igual ao cálculo histórico.
     */
    public static function accessKeyDigit(string $baseKey): int
    {
        $baseKey = strtoupper(trim($baseKey));

        if (strlen($baseKey) !== 43 || !preg_match('/^[0-9]{6}[A-Z0-9]{12}[0-9]{25}$/', $baseKey)) {
            throw new InvalidFiscalDataException('A base da chave não corresponde ao formato oficial de 43 posições.');
        }

        $sum = 0;
        $weight = 2;

        for ($i = 42; $i >= 0; $i--) {
            $value = ord($baseKey[$i]) - 48;
            $sum += $value * $weight;

            $weight++;
            if ($weight > 9) {
                $weight = 2;
            }
        }

        $remainder = $sum % 11;

        return in_array($remainder, [0, 1], true)
            ? 0
            : 11 - $remainder;
    }
}
