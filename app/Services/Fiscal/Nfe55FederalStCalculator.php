<?php

namespace App\Services\Fiscal;

use RuntimeException;

/** Explicit PIS/COFINS-ST bases; the caller classifies incidence and document-total treatment. */
final class Nfe55FederalStCalculator
{
    public function calculate(array $tax): array
    {
        $pis = $this->calculateGroup($tax, 'pis');
        $cofins = $this->calculateGroup($tax, 'cofins');

        return [
            'pis_st' => $pis['fields'] ?? [], 'cofins_st' => $cofins['fields'] ?? [],
            'pis_st_amount' => $pis['amount'] ?? 0, 'cofins_st_amount' => $cofins['amount'] ?? 0,
            'total_addition' => ($pis['total_addition'] ?? 0) + ($cofins['total_addition'] ?? 0),
        ];
    }

    /** Amount is integer cents. PISST/COFINSST have no CST field or inherited ordinary-tax base. */
    public function calculateGroup(array $tax, string $name): ?array
    {
        if (!in_array($name, ['pis', 'cofins'], true)) {
            throw new RuntimeException('Contribuição ST inválida.');
        }
        $prefix = $name.'_st_';
        $label = strtoupper($name).' ST';
        $parameters = [];
        foreach ($tax as $key => $value) {
            if (!str_starts_with((string) $key, $prefix)
                || str_ends_with((string) $key, '_inbound') || str_ends_with((string) $key, '_export')
                || $value === null || $value === '') continue;
            $suffix = substr((string) $key, strlen($prefix));
            if (!in_array($suffix, ['calculation_type', 'calc_type', 'base', 'rate', 'quantity', 'unit_rate', 'add_to_total'], true)) {
                throw new RuntimeException($label.': parâmetro sem suporte: '.$key.'.');
            }
            $parameters[$suffix] = $value;
        }
        if (!$parameters) return null;

        $mode = $this->mode($parameters['calculation_type'] ?? '');
        $legacyMode = $this->mode($parameters['calc_type'] ?? '');
        if ($mode !== '' && $legacyMode !== '' && $mode !== $legacyMode) {
            throw new RuntimeException($label.': modos de cálculo divergentes.');
        }
        if ($mode === '') $mode = $legacyMode;
        if ($mode === '' || $mode === 'none') {
            // Existing profiles may store an explicitly inactive group with zero rates.
            foreach (['base', 'rate', 'quantity', 'unit_rate'] as $field) {
                if (array_key_exists($field, $parameters)
                    && NfeTaxMath::scaled($parameters[$field], $field === 'base' ? 2 : 4, $label) !== 0) {
                    throw new RuntimeException($label.': informe o modo de cálculo explícito.');
                }
            }
            if (array_key_exists('add_to_total', $parameters) && $this->totalIndicator($parameters['add_to_total'], $label) !== '0') {
                throw new RuntimeException($label.': indicador de soma exige um grupo ST calculado.');
            }
            return null;
        }
        if ($mode === 'percent') {
            $this->assertAbsent($parameters, ['quantity', 'unit_rate'], $label);
            $base = NfeTaxMath::scaled($parameters['base'] ?? null, 2, $label.' base');
            $rate = NfeTaxMath::rate($parameters['rate'] ?? null, $label.' alíquota');
            $amount = NfeTaxMath::product($base, $rate, 6);
            $this->assertMoneyLimit($base, $label.' base');
            $fields = ['vBC' => NfeTaxMath::format($base),
                'p'.strtoupper($name) => NfeTaxMath::format($rate, 4)];
        } elseif ($mode === 'quantity') {
            $this->assertAbsent($parameters, ['base', 'rate'], $label);
            $quantity = NfeTaxMath::scaled($parameters['quantity'] ?? null, 4, $label.' quantidade');
            if ($quantity <= 0) throw new RuntimeException($label.': quantidade tributável deve ser positiva.');
            $unitRate = NfeTaxMath::scaled($parameters['unit_rate'] ?? null, 4, $label.' alíquota por unidade');
            if ($quantity > 9999999999999999 || $unitRate > 999999999999999) {
                throw new RuntimeException($label.': quantidade ou alíquota excede o limite do documento fiscal.');
            }
            $amount = NfeTaxMath::product($quantity, $unitRate, 6);
            $fields = ['qBCProd' => NfeTaxMath::format($quantity, 4),
                'vAliqProd' => NfeTaxMath::format($unitRate, 4)];
        } else {
            throw new RuntimeException($label.': modo de cálculo inválido.');
        }
        $this->assertMoneyLimit($amount, $label.' valor');
        $fields['v'.strtoupper($name)] = NfeTaxMath::format($amount);
        $indicator = $this->totalIndicator($parameters['add_to_total'] ?? null, $label);
        $fields['indSoma'.strtoupper($name).'ST'] = $indicator;

        return ['fields' => $fields, 'amount' => $amount, 'total_addition' => $indicator === '1' ? $amount : 0];
    }

    private function mode(mixed $mode): string
    {
        if (!is_string($mode)) throw new RuntimeException('Contribuição ST: modo de cálculo inválido.');
        return $mode === 'percentage' ? 'percent' : $mode;
    }

    private function assertAbsent(array $parameters, array $fields, string $label): void
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $parameters)) {
                throw new RuntimeException($label.': modos percentual e por quantidade não podem ser combinados.');
            }
        }
    }

    private function totalIndicator(mixed $indicator, string $label): string
    {
        if (!in_array($indicator, [0, 1, '0', '1'], true)) {
            throw new RuntimeException($label.': informe o indicador de soma ao total como 0 ou 1.');
        }
        return (string) $indicator;
    }

    private function assertMoneyLimit(int $value, string $label): void
    {
        if ($value > 999999999999999) throw new RuntimeException($label.': valor excede o limite do documento fiscal.');
    }
}
