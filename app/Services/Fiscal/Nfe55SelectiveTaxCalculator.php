<?php

namespace App\Services\Fiscal;

use DateTimeImmutable;
use RuntimeException;

/** TIS arithmetic from explicit reviewed parameters; no incidence, classification or rate is inferred. */
final class Nfe55SelectiveTaxCalculator
{
    public function calculate(array $tax, string $issueDate): ?array
    {
        $parameters = [];
        foreach ($tax as $key => $value) {
            if (!str_starts_with((string) $key, 'is_') || str_ends_with((string) $key, '_inbound')
                || str_ends_with((string) $key, '_export') || $value === null || $value === '') continue;
            if (!in_array($key, ['is_cst', 'is_class', 'is_calculation_type', 'is_base', 'is_rate',
                'is_unit_rate', 'is_quantity', 'is_unit', 'is_valid_from', 'is_valid_until',
                'is_legal_source', 'is_legal_reference'], true)) {
                throw new RuntimeException('Imposto Seletivo: parâmetro sem suporte: '.$key.'.');
            }
            $parameters[$key] = $value;
        }
        if (!$parameters) return null;
        // Existing tax profiles may store only an inactive zero rate, including in 2026.
        if (array_keys($parameters) === ['is_rate']
            && NfeTaxMath::scaled($parameters['is_rate'], 4, 'Imposto Seletivo alíquota') === 0) return null;

        $date = $this->date($issueDate, 'data de emissão');
        if ($date < '2027-01-01') {
            throw new RuntimeException('Imposto Seletivo: este cálculo não admite destaque antes de 2027.');
        }
        $start = $this->date($parameters['is_valid_from'] ?? null, 'início de vigência');
        $end = $this->date($parameters['is_valid_until'] ?? null, 'fim de vigência');
        if ($start < '2027-01-01' || $end < $start || $date < $start || $date > $end) {
            throw new RuntimeException('Imposto Seletivo: parâmetros fora da vigência jurídica informada.');
        }
        $source = $parameters['is_legal_source'] ?? null;
        $reference = $parameters['is_legal_reference'] ?? null;
        if (!is_string($source) || !filter_var($source, FILTER_VALIDATE_URL)
            || parse_url($source, PHP_URL_SCHEME) !== 'https'
            || !is_string($reference) || trim($reference) === '') {
            throw new RuntimeException('Imposto Seletivo: informe fonte normativa HTTPS e referência legal explícitas.');
        }
        $host=strtolower((string)parse_url($source,PHP_URL_HOST));
        if(!(str_ends_with($host,'.gov.br')||str_ends_with($host,'.leg.br')))throw new RuntimeException('Imposto Seletivo: use fonte normativa governamental.');
        $cst = $parameters['is_cst'] ?? null;
        $classification = $parameters['is_class'] ?? null;
        if (!is_string($cst) || !preg_match('/^\d{3}$/D', $cst)
            || !is_string($classification) || !preg_match('/^\d{6}$/D', $classification)) {
            throw new RuntimeException('Imposto Seletivo: informe CST de três dígitos e classificação de seis dígitos.');
        }
        $mode = $parameters['is_calculation_type'] ?? null;
        if (!in_array($mode, ['percent', 'quantity', 'combined'], true)) {
            throw new RuntimeException('Imposto Seletivo: escolha explicitamente cálculo percent, quantity ou combined.');
        }
        // vBCIS and pIS remain mandatory inside the taxable sequence, including quantity mode.
        $base = NfeTaxMath::scaled($parameters['is_base'] ?? null, 2, 'Imposto Seletivo base');
        $this->moneyLimit($base);
        $rate = $this->rate($parameters['is_rate'] ?? null, 'percentual');
        $fields = ['CSTIS' => $cst, 'cClassTribIS' => $classification,
            'vBCIS' => NfeTaxMath::format($base), 'pIS' => NfeTaxMath::format($rate, 4)];
        $quantity = 0;
        $unitRate = 0;
        if ($mode === 'quantity' && $rate !== 0) {
            throw new RuntimeException('Imposto Seletivo: cálculo por quantidade exige percentual explicitamente zero.');
        }
        if ($mode === 'percent') {
            foreach (['is_unit_rate', 'is_quantity', 'is_unit'] as $field) {
                if (array_key_exists($field, $parameters)) {
                    throw new RuntimeException('Imposto Seletivo: parâmetros por unidade exigem modo quantity ou combined.');
                }
            }
        } else {
            $unitRate = $this->rate($parameters['is_unit_rate'] ?? null, 'por unidade');
            $quantity = NfeTaxMath::scaled($parameters['is_quantity'] ?? null, 4, 'Imposto Seletivo quantidade');
            if ($quantity <= 0 || $quantity > 999999999999999) {
                throw new RuntimeException('Imposto Seletivo: quantidade deve ser positiva com até onze inteiros e quatro decimais.');
            }
            $unit = $parameters['is_unit'] ?? null;
            if (!is_string($unit)
                || !preg_match('/^[\x{0021}-\x{00FF}](?:[\x{0020}-\x{00FF}]{0,4}[\x{0021}-\x{00FF}])?$/uD', $unit)) {
                throw new RuntimeException('Imposto Seletivo: informe unidade tributável de um a seis caracteres válidos.');
            }
            $fields += ['adRemIS' => NfeTaxMath::format($unitRate, 4),
                'uTrib' => $unit, 'qTrib' => NfeTaxMath::format($quantity, 4)];
        }
        $amount = $this->combinedAmount($base, $rate, $quantity, $unitRate);
        $this->moneyLimit($amount);
        $fields['vIS'] = NfeTaxMath::format($amount);

        return ['fields' => $fields, 'amount' => $amount, 'metadata' => [
            'legal_source' => $source, 'legal_reference' => trim($reference),
            'valid_from' => $start, 'valid_until' => $end,
            'classification_inferred' => false, 'classification_catalog_verified' => false,
        ]];
    }

    /** Both products have six fractional digits of cents; round their sum once per item. */
    private function combinedAmount(int $base, int $rate, int $quantity, int $unitRate): int
    {
        $percent = NfeTaxMath::product($base, $rate, 6);
        $specific = NfeTaxMath::product($quantity, $unitRate, 6);
        // Modular products recover the exact fractional cents without overflowing native integers.
        $percentRemainder = (($base % 1000000) * ($rate % 1000000)) % 1000000;
        $specificRemainder = (($quantity % 1000000) * ($unitRate % 1000000)) % 1000000;
        $whole = $percent - (int) ($percentRemainder >= 500000)
            + $specific - (int) ($specificRemainder >= 500000);
        return $whole + intdiv($percentRemainder + $specificRemainder + 500000, 1000000);
    }

    /** TDec_0302_04RTC permits three integer digits for pIS and adRemIS. */
    private function rate(mixed $value, string $label): int
    {
        $rate = NfeTaxMath::scaled($value, 4, 'Imposto Seletivo alíquota '.$label);
        if ($rate > 9999999) throw new RuntimeException('Imposto Seletivo: alíquota excede três inteiros e quatro decimais.');
        return $rate;
    }

    private function moneyLimit(int $amount): void
    {
        if ($amount > 999999999999999) throw new RuntimeException('Imposto Seletivo: valor excede o limite do documento fiscal.');
    }

    private function date(mixed $value, string $label): string
    {
        $date = is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)
            ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new RuntimeException('Imposto Seletivo: '.$label.' ausente ou inválida.');
        }
        return $value;
    }
}
