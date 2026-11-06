<?php

namespace App\Services\Fiscal;

use RuntimeException;

/** Returned IPI from the original full item amount and its explicit returned percentage. */
final class Nfe55ReturnedIpiCalculator
{
    /** The caller validates the return operation and serializes fields in impostoDevolNNN. */
    public function calculate(array $tax): ?array
    {
        $parameters = [];
        foreach ($tax as $key => $value) {
            if (!str_starts_with((string) $key, 'ipi_returned_')
                || str_ends_with((string) $key, '_inbound') || str_ends_with((string) $key, '_export')
                || $value === null || $value === '') continue;
            if (!in_array($key, ['ipi_returned_percentage', 'ipi_returned_original_amount'], true)) {
                throw new RuntimeException('IPI devolvido: parâmetro sem suporte: '.$key.'.');
            }
            $parameters[$key] = $value;
        }
        if (!$parameters) return null;

        $percentage = NfeTaxMath::scaled($parameters['ipi_returned_percentage'] ?? null, 2, 'IPI devolvido percentual');
        if ($percentage <= 0 || $percentage > 10000) {
            throw new RuntimeException('IPI devolvido: percentual deve ser maior que zero e até 100%.');
        }
        $original = NfeTaxMath::scaled($parameters['ipi_returned_original_amount'] ?? null, 2, 'IPI original do item completo');
        if ($original > 999999999999999) {
            throw new RuntimeException('IPI original: valor excede o limite do documento fiscal.');
        }
        $amount = NfeTaxMath::product($original, $percentage, 4);

        return ['fields' => ['pDevol' => NfeTaxMath::format($percentage),
            'vIPIDevol' => NfeTaxMath::format($amount)], 'amount' => $amount];
    }
}
