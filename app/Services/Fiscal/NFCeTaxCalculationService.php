<?php

namespace App\Services\Fiscal;

use RuntimeException;

/**
 * Cálculos por situação fiscal já mapeada para o modelo 65.
 * Não escolhe CST, CSOSN, CFOP ou alíquotas: lê apenas a configuração.
 * Os cenários não mapeados continuam falhando antes do envio à SEFAZ.
 */
final class NFCeTaxCalculationService
{
    private const SIMPLE_CFOPS = ['5101', '5102', '5103', '5104', '5115'];
    private const RETAINED_ST_CFOPS = ['5405', '5656', '5667'];

    /** @return array{icms:array,pis:array,cofins:array} */
    public function calculate(array $item): array
    {
        $tax = is_array($item['tax_defaults'] ?? null) ? $item['tax_defaults'] : [];
        $cfop = (string) ($tax['cfop_outbound_internal'] ?? $tax['nfce_cfop'] ?? $tax['cfop'] ?? '');
        $csosn = (string) ($tax['icms_csosn'] ?? $tax['csosn'] ?? '');
        $origin = (string) ($item['origin'] ?? $tax['icms_origin_default'] ?? '');
        if (!preg_match('/^[0-8]$/', $origin)) {
            throw new RuntimeException('Origem da mercadoria ausente ou inválida.');
        }
        if (in_array($csosn, ['102', '103', '300', '400'], true)) {
            if (!in_array($cfop, self::SIMPLE_CFOPS, true)) {
                throw new RuntimeException("CFOP {$cfop} incompatível com CSOSN {$csosn} na NFC-e.");
            }
            $icms = ['orig' => $origin, 'CSOSN' => $csosn];
        } elseif ($csosn === '500') {
            if (!in_array($cfop, self::RETAINED_ST_CFOPS, true)) {
                throw new RuntimeException("CSOSN 500 exige CFOP de operação com ICMS-ST retido compatível.");
            }
            $icms = [
                'orig' => $origin,
                'CSOSN' => $csosn,
                'vBCSTRet' => $this->moneyField($tax, 'icms_st_retained_base'),
                'vICMSSTRet' => $this->moneyField($tax, 'icms_st_retained_value'),
            ];
            // Campos complementares de ICMS efetivo, quando exigidos pela UF,
            // não são presumidos. A validação por UF continua obrigatória.
            foreach (['st_retained_rate'=>'pST', 'icms_effective_base'=>'vBCEfet',
                'icms_effective_rate'=>'pICMSEfet', 'icms_effective_value'=>'vICMSEfet'] as $from=>$to) {
                if (array_key_exists($from, $tax) && $tax[$from] !== '') {
                    $icms[$to] = str_starts_with($to, 'p')
                        ? $this->rate($tax, $from)
                        : $this->moneyField($tax, $from);
                }
            }
        } else {
            throw new RuntimeException("CSOSN {$csosn} ainda não possui cálculo e XML NFC-e homologados.");
        }

        $qty = $this->decimal($item['quantity'] ?? null, 'Quantidade');
        $unit = $this->decimal($item['unit_price'] ?? null, 'Preço unitário');
        if ($qty <= 0 || $unit < 0) {
            throw new RuntimeException('Quantidade ou preço unitário inválido.');
        }
        $gross = round($qty * $unit, 2, PHP_ROUND_HALF_UP);
        $discount = $this->decimal($item['discount'] ?? 0, 'Desconto');
        if ($discount < 0 || $discount > $gross) {
            throw new RuntimeException('Desconto maior que o valor bruto ou negativo.');
        }
        $base = round($gross - $discount, 2, PHP_ROUND_HALF_UP);

        return [
            'icms' => $icms,
            'pis' => $this->contribution('pis', $tax, $base),
            'cofins' => $this->contribution('cofins', $tax, $base),
        ];
    }

    private function contribution(string $type, array $tax, float $base): array
    {
        $cst = (string) ($tax[$type.'_cst'] ?? '');
        $rateField = $type.'_rate';
        $mode = (string) ($tax[$type.'_calc_type'] ?? 'none');
        $namedRate = $type === 'pis' ? 'pPIS' : 'pCOFINS';
        $namedAmount = $type === 'pis' ? 'vPIS' : 'vCOFINS';

        if (in_array($cst, ['04', '06', '07', '08', '09'], true)) {
            if ($mode !== 'none' || $this->hasNonzero($tax, $rateField)) {
                throw new RuntimeException("CST {$cst} de ".strtoupper($type)." não admite a alíquota configurada.");
            }
            return ['CST' => $cst];
        }
        if (!in_array($cst, ['01', '02', '49', '99'], true)) {
            throw new RuntimeException("CST {$cst} de ".strtoupper($type)." ainda não tem cálculo implementado.");
        }
        if ($mode === 'none' || $mode === '') {
            if (in_array($cst, ['01', '02'], true) || $this->hasNonzero($tax, $rateField)) {
                throw new RuntimeException("Configure cálculo percentual e alíquota para ".strtoupper($type)." CST {$cst}.");
            }
            // Compatibilidade estrita com o cadastro anterior: CST 49/99 sem
            // alíquota explícita corresponde à tributação zerada já utilizada.
            return ['CST' => $cst, 'vBC' => '0.00', $namedRate => '0.0000', $namedAmount => '0.00'];
        }
        if ($mode !== 'percentage') {
            throw new RuntimeException("Tipo de cálculo de ".strtoupper($type)." não implementado: {$mode}.");
        }
        $rate = $this->rate($tax, $rateField);
        $amount = round($base * (float) $rate / 100, 2, PHP_ROUND_HALF_UP);
        return [
            'CST' => $cst,
            'vBC' => number_format($base, 2, '.', ''),
            $namedRate => $rate,
            $namedAmount => number_format($amount, 2, '.', ''),
        ];
    }

    private function hasNonzero(array $values, string $key): bool
    {
        return isset($values[$key]) && $values[$key] !== ''
            && is_numeric($values[$key]) && (float) $values[$key] != 0.0;
    }

    private function rate(array $tax, string $key): string
    {
        if (!array_key_exists($key, $tax) || $tax[$key] === '' || !is_numeric($tax[$key])) {
            throw new RuntimeException("Alíquota {$key} não foi configurada.");
        }
        $value = (float) $tax[$key];
        if (!is_finite($value) || $value < 0 || $value > 100) {
            throw new RuntimeException("Alíquota {$key} fora da faixa permitida.");
        }
        return number_format($value, 4, '.', '');
    }

    private function moneyField(array $tax, string $key): string
    {
        if (!array_key_exists($key, $tax) || $tax[$key] === '') {
            throw new RuntimeException("Informe {$key} para CSOSN 500; o NEXTOR não presume ST retido.");
        }
        return number_format($this->decimal($tax[$key], $key), 2, '.', '');
    }

    private function decimal(mixed $raw, string $label): float
    {
        if (!is_numeric($raw)) {
            throw new RuntimeException("Valor inválido: {$label}.");
        }
        $value = (float) $raw;
        if (!is_finite($value) || $value < 0) {
            throw new RuntimeException("Valor inválido: {$label}.");
        }
        return $value;
    }
}
