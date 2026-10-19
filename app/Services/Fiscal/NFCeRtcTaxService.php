<?php

namespace App\Services\Fiscal;

use RuntimeException;

/**
 * Reforma tributária do consumo: grupo regular gIBSCBS da NFC-e.
 *
 * Suporta estritamente a classificação integral CST 000 / cClassTrib
 * 000001, sem redução, diferimento, devolução ou monofásico.
 * Os códigos e as três alíquotas vêm exclusivamente da regra/grupo
 * tributário aprovado pelo escritório, jamais de constantes do sistema.
 *
 * Outras classificações exigem motor específico, não são interpretadas
 * com a fórmula de tributação integral.
 */
final class NFCeRtcTaxService
{
    /**
     * @return array{
     *  ibscbs:array,gibscbs:array,gibsuf:array,gibsmun:array,gcbs:array
     * }|null
     */
    public function calculate(array $item, string $crt, ?string $issueDate = null): ?array
    {
        $tax = is_array($item['tax_defaults'] ?? null) ? $item['tax_defaults'] : [];
        $names = [
            'ibs_cbs_cst', 'ibs_cbs_class',
            'ibs_uf_rate', 'ibs_municipal_rate', 'cbs_rate',
        ];
        $present = array_filter($names, static fn(string $name): bool =>
            array_key_exists($name, $tax) && $tax[$name] !== null && trim((string)$tax[$name]) !== ''
        );
        if (!$present) {
            // O Simples Nacional permanece no regime de 2026 sem IBS/CBS.
            // A partir de 2027 as regras serão distintas, inclusive opção
            // de recolhimento regular, e não podem ser presumidas aqui.
            if (in_array($crt, ['1', '4'], true) && $issueDate !== null
                && substr($issueDate, 0, 10) >= '2027-01-01') {
                throw new RuntimeException('IBS/CBS 2027: configure o enquadramento do Simples Nacional e a opção pelo regime regular antes de emitir.');
            }
            return null;
        }

        foreach ($names as $name) {
            if (!isset($tax[$name]) || trim((string)$tax[$name]) === '') {
                throw new RuntimeException("RTC: o campo {$name} deve estar configurado no grupo tributário.");
            }
        }
        if ((string)$tax['ibs_cbs_cst'] !== '000'
            || (string)$tax['ibs_cbs_class'] !== '000001') {
            throw new RuntimeException('RTC: somente CST 000 e cClassTrib 000001 têm cálculo padrão implementado. Configure um perfil fiscal específico para outros tratamentos.');
        }
        if (in_array($crt, ['1', '4'], true)) {
            throw new RuntimeException('RTC do Simples Nacional: não aplicar cálculo de regime regular sem opção e parametrização específica do emitente.');
        }
        foreach ([
            'cbs_reduction_rate', 'ibs_uf_reduction_rate', 'ibs_municipal_reduction_rate',
            'cbs_deferral_rate', 'ibs_uf_deferral_rate', 'ibs_municipal_deferral_rate',
            'ibs_reduction_rate', 'is_rate', 'is_cst', 'is_class',
        ] as $unsupported) {
            if (isset($tax[$unsupported]) && (float)$tax[$unsupported] != 0.0) {
                throw new RuntimeException("RTC: {$unsupported} exige cálculo próprio; não usar tributação integral.");
            }
        }
        $qty = $this->number($item['quantity'] ?? null, 'quantidade');
        $price = $this->number($item['unit_price'] ?? null, 'preço');
        $discount = $this->number($item['discount'] ?? 0, 'desconto');
        if ($qty <= 0 || $price < 0) {
            throw new RuntimeException('RTC: quantidade ou preço inválido.');
        }
        $gross = round($qty * $price, 2, PHP_ROUND_HALF_UP);
        if ($discount > $gross) {
            throw new RuntimeException('RTC: desconto maior que o valor bruto.');
        }
        $base = round($gross - $discount, 2, PHP_ROUND_HALF_UP);
        $ufRate = $this->rate($tax, 'ibs_uf_rate');
        $munRate = $this->rate($tax, 'ibs_municipal_rate');
        $cbsRate = $this->rate($tax, 'cbs_rate');

        $ibsUf = $this->money($base * (float)$ufRate / 100);
        $ibsMun = $this->money($base * (float)$munRate / 100);
        $cbs = $this->money($base * (float)$cbsRate / 100);
        $ibs = $this->money((float)$ibsUf + (float)$ibsMun);

        return [
            'ibscbs' => [
                'CST' => '000',
                'cClassTrib' => '000001',
            ],
            'gibscbs' => ['vBC' => $this->money($base), 'vIBS' => $ibs],
            'gibsuf' => ['pIBSUF' => $ufRate, 'vIBSUF' => $ibsUf],
            'gibsmun' => ['pIBSMun' => $munRate, 'vIBSMun' => $ibsMun],
            'gcbs' => ['pCBS' => $cbsRate, 'vCBS' => $cbs],
        ];
    }

    private function rate(array $tax, string $name): string
    {
        $value = $this->number($tax[$name], $name);
        if ($value > 100) {
            throw new RuntimeException("RTC: {$name} acima de 100%.");
        }
        return number_format($value, 4, '.', '');
    }

    private function number(mixed $raw, string $name): float
    {
        if (!is_numeric($raw) || !is_finite((float)$raw) || (float)$raw < 0) {
            throw new RuntimeException("RTC: valor inválido em {$name}.");
        }
        return (float)$raw;
    }

    private function money(float $value): string
    {
        return number_format(round($value, 2, PHP_ROUND_HALF_UP), 2, '.', '');
    }
}
