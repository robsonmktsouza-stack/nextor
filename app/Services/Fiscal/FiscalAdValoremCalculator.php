<?php

namespace App\Services\Fiscal;

use InvalidArgumentException;

/**
 * Deterministic ad-valorem tax arithmetic in integer cents.
 *
 * Tax applicability, taxable base, rates and legal exemptions must be resolved
 * upstream from reviewed fiscal rules. This class does not decide taxation.
 */
final class FiscalAdValoremCalculator
{
    /**
     * @return array{base_cents:int,rate_percent:string,amount_cents:int}
     */
    public function calculate(int $baseCents, string $ratePercent): array
    {
        if ($baseCents < 0 || !preg_match('/^\d{1,3}(?:\.\d{1,4})?$/D', $ratePercent)) {
            throw new InvalidArgumentException('Invalid tax base or percentage.');
        }
        $parts = explode('.', $ratePercent, 2);
        $scaled = (int) $parts[0] * 10000
            + (int) str_pad($parts[1] ?? '', 4, '0', STR_PAD_RIGHT);
        if ($scaled > 100 * 10000) {
            throw new InvalidArgumentException('Percentage exceeds 100%.');
        }
        // Rate has 4 decimal places; half-up to one cent, avoiding float drift.
        $amount = intdiv($baseCents * $scaled + 500000, 1000000);
        return [
            'base_cents' => $baseCents,
            'rate_percent' => $ratePercent,
            'amount_cents' => $amount,
        ];
    }

    public function formatCents(int $cents): string
    {
        if ($cents < 0) {
            throw new InvalidArgumentException('Negative monetary amount.');
        }
        return intdiv($cents, 100).'.'.str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
