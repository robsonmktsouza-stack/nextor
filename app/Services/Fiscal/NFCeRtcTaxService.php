<?php

namespace App\Services\Fiscal;

use RuntimeException;

/**
 * Reforma tributária do consumo: grupo regular gIBSCBS da NFC-e.
 *
 * Suporta as classificações integral 000/000001 e de alíquota zero
 * 200/200003 (alimentação humana enquadrada no Anexo I, quando correta
 * a classificação do produto pelo escritório). Sem diferimento ou monofasia.
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
    public function calculate(array $item, string $crt, ?string $issueDate = null, array $legacyTaxes = []): ?array
    {
        $tax = is_array($item['tax_defaults'] ?? null) ? $item['tax_defaults'] : [];
        $names = [
            'ibs_cbs_cst', 'ibs_cbs_class',
            'ibs_uf_rate', 'ibs_municipal_rate', 'cbs_rate',
        ];
        // Imposto Seletivo tem grupo e vigência próprios. Mesmo CST "000"
        // NÃO é um valor numérico zero descartável: sua presença precisa
        // ser tratada pelo XML IS específico, ainda não implementado.
        foreach (['is_cst','is_class'] as $field) {
            if (isset($tax[$field]) && trim((string)$tax[$field]) !== '') {
                throw new RuntimeException("RTC: {$field} requer emissão do grupo Imposto Seletivo; não transmitir omitindo o dado.");
            }
        }
        foreach (['is_rate','cbs_deferral_rate',
            'ibs_uf_deferral_rate','ibs_municipal_deferral_rate',
            'ibs_reduction_rate'] as $field) {
            if (isset($tax[$field]) && trim((string)$tax[$field]) !== ''
                && (!is_numeric($tax[$field]) || (float)$tax[$field] != 0.0)) {
                throw new RuntimeException("RTC: {$field} requer cálculo específico ainda não disponível na NFC-e.");
            }
        }

        $present = array_filter($names, static fn(string $name): bool =>
            array_key_exists($name, $tax) && $tax[$name] !== null && trim((string)$tax[$name]) !== ''
        );
        if (!$present) {
            // O adiamento de rejeição automática em 2026 NÃO suspendeu a
            // obrigação de destaque para o regime regular. O emissor não
            // pode transmitir uma NFC-e regular omitindo o bloco fiscal.
            if (in_array($crt, ['2','3'], true) && $issueDate !== null
                && substr($issueDate, 0, 10) >= '2026-08-03') {
                throw new RuntimeException('RTC: o regime normal exige classificação e alíquotas IBS/CBS por item. Configure o grupo tributário antes da emissão.');
            }
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
        $cst = (string)$tax['ibs_cbs_cst'];
        $classification = (string)$tax['ibs_cbs_class'];
        $integral = $cst === '000' && $classification === '000001';
        // Tabela cClassTrib IT 2025.002 v1.70: 200003, alimentos do
        // Anexo I (redução integral). Nenhuma classificação é deduzida
        // automaticamente pelo NCM.
        $zeroRatedFood = $cst === '200' && $classification === '200003';
        if (!$integral && !$zeroRatedFood) {
            throw new RuntimeException('RTC: CST/cClassTrib ainda sem mapeamento específico válido para NFC-e; confira a tabela oficial antes da emissão.');
        }
        if (in_array($crt, ['1', '4'], true)) {
            throw new RuntimeException('RTC do Simples Nacional: não aplicar cálculo de regime regular sem opção e parametrização específica do emitente.');
        }
        foreach ([
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
        // LC 214/2025, art. 12, parágrafo 2º, V (2026-2032):
        // ICMS, PIS e COFINS da operação não entram na base de IBS/CBS.
        // Reaproveitar exclusivamente os valores já calculados para o XML
        // pelo motor ICMS/PIS/COFINS, evitando duas bases divergentes.
        $legacyDeduction = 0.0;
        $day = $issueDate !== null ? substr($issueDate, 0, 10) : null;
        if ($day === null || ($day >= '2026-01-01' && $day <= '2032-12-31')) {
            foreach (['icms'=>'vICMS', 'pis'=>'vPIS', 'cofins'=>'vCOFINS'] as $taxType=>$field) {
                if (!array_key_exists($taxType, $legacyTaxes)) {
                    continue;
                }
                $value = $this->number($legacyTaxes[$taxType][$field] ?? 0, 'tributo '.$field);
                $legacyDeduction += $value;
            }
        }
        $base = round($gross - $discount - $legacyDeduction, 2, PHP_ROUND_HALF_UP);
        if ($base < 0) {
            throw new RuntimeException('RTC: ICMS, PIS e COFINS excedem o valor líquido do item.');
        }
        $ufRate = $this->rate($tax, 'ibs_uf_rate');
        $munRate = $this->rate($tax, 'ibs_municipal_rate');
        $cbsRate = $this->rate($tax, 'cbs_rate');

        $reduction = [];
        foreach ([
            'ibs_uf_reduction_rate' => 'uf',
            'ibs_municipal_reduction_rate' => 'mun',
            'cbs_reduction_rate' => 'cbs',
        ] as $field=>$destination) {
            $raw=$tax[$field] ?? null;
            if ($zeroRatedFood) {
                if ($raw === null || $raw === '' || $this->rate($tax,$field) !== '100.0000') {
                    throw new RuntimeException("RTC 200003: {$field} deve ser 100%, conforme classificação oficial.");
                }
                $reduction[$destination]=['pRedAliq'=>'100.0000','pAliqEfet'=>'0.0000'];
            } elseif ($raw !== null && $raw !== '' && (float)$raw != 0.0) {
                throw new RuntimeException("RTC: {$field} não é permitido para CST 000 integral.");
            }
        }

        $ibsUf = $this->money($base * (float)$ufRate / 100);
        $ibsMun = $this->money($base * (float)$munRate / 100);
        $cbs = $this->money($base * (float)$cbsRate / 100);
        if ($zeroRatedFood) {
            $ibsUf='0.00';
            $ibsMun='0.00';
            $cbs='0.00';
        }
        $ibs = $this->money((float)$ibsUf + (float)$ibsMun);

        return [
            'ibscbs' => [
                'CST' => $cst,
                'cClassTrib' => $classification,
            ],
            'gibscbs' => ['vBC' => $this->money($base), 'vIBS' => $ibs],
            'gibsuf' => array_merge(['pIBSUF' => $ufRate, 'vIBSUF' => $ibsUf],$reduction['uf'] ?? []),
            'gibsmun' => array_merge(['pIBSMun' => $munRate, 'vIBSMun' => $ibsMun],$reduction['mun'] ?? []),
            'gcbs' => array_merge(['pCBS' => $cbsRate, 'vCBS' => $cbs],$reduction['cbs'] ?? []),
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
