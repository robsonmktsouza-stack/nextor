<?php

namespace App\Services\Fiscal;

use RuntimeException;

/**
 * Calculates only supported ad-valorem PIS/COFINS groups from an approved
 * tax configuration. Does not infer CST or rate from product/NCM.
 */
final class NfeFederalItemTaxCalculator
{
    /**
     * @param array<string,mixed> $tax
     * @return array{pis:array<string,string>,cofins:array<string,string>,pis_amount:int,cofins_amount:int}
     */
    public function calculate(array $tax, int $baseCents): array
    {
        if ($baseCents < 0) {
            throw new RuntimeException('Base tributável negativa.');
        }
        $pis = $this->group($tax, 'pis', $baseCents);
        $cofins = $this->group($tax, 'cofins', $baseCents);
        return [
            'pis' => $pis['fields'],
            'cofins' => $cofins['fields'],
            'pis_amount' => $pis['amount'],
            'cofins_amount' => $cofins['amount'],
        ];
    }

    /** @return array{fields:array<string,string>,amount:int} */
    private function group(array $tax, string $name, int $baseCents): array
    {
        $cst = trim((string)($tax[$name.'_cst'] ?? ''));
        $rate = $tax[$name.'_rate'] ?? null;
        if (in_array($cst, ['01','02'], true)) {
            if (!is_numeric($rate) || (float)$rate < 0 || (float)$rate > 100) {
                throw new RuntimeException(strtoupper($name).': alíquota percentual inválida ou ausente.');
            }
            $rateBasisPoints = (int)round((float)$rate * 10000);
            $amount = intdiv($baseCents * $rateBasisPoints + 500000, 1000000);
            return ['fields' => [
                'CST' => $cst,
                'vBC' => number_format($baseCents / 100, 2, '.', ''),
                'p'.strtoupper($name) => number_format((float)$rate, 4, '.', ''),
                'v'.strtoupper($name) => number_format($amount / 100, 2, '.', ''),
            ], 'amount' => $amount];
        }
        if (in_array($cst, ['49','99'], true)) {
            if ($rate !== null && $rate !== '' && (float)$rate !== 0.0) {
                throw new RuntimeException(strtoupper($name).': CST residual com alíquota informada exige grupo específico.');
            }
            return ['fields' => ['CST' => $cst, 'vBC' => '0.00',
                'p'.strtoupper($name) => '0.00', 'v'.strtoupper($name) => '0.00'], 'amount' => 0];
        }
        throw new RuntimeException(strtoupper($name).': CST sem cálculo homologado.');
    }
}
