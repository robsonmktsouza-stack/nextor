<?php

namespace App\Services\Fiscal;

use RuntimeException;

/** Calculates the federal groups from explicit, reviewed item parameters; CST never supplies a rate. */
final class NfeFederalItemTaxCalculator
{
    /** All amounts are integer cents. The caller remains responsible for legal base composition. */
    public function calculate(array $tax, int $baseCents): array
    {
        if ($baseCents < 0) throw new RuntimeException('Base tributável negativa.');
        $pis = $this->calculateGroup($tax, 'pis', $baseCents);
        $cofins = $this->calculateGroup($tax, 'cofins', $baseCents);
        $ipi = isset($tax['ipi_cst']) ? $this->calculateGroup($tax, 'ipi', $baseCents) : ['fields' => [], 'amount' => 0];
        return [
            'pis' => $pis['fields'], 'cofins' => $cofins['fields'], 'ipi' => $ipi['fields'],
            'pis_amount' => $pis['amount'], 'cofins_amount' => $cofins['amount'], 'ipi_amount' => $ipi['amount'],
        ];
    }

    public function calculateGroup(array $tax, string $name, int $baseCents): array
    {
        $tax=Nfe55TaxParameters::forDirection($tax,'outbound');
        if ($baseCents < 0) throw new RuntimeException('Base tributável negativa.');
        return match ($name) {
            'pis', 'cofins' => $this->contribution($tax, $name, $baseCents),
            'ipi' => $this->ipi($tax, $baseCents),
            default => throw new RuntimeException('Tributo federal inválido.'),
        };
    }

    private function contribution(array $tax, string $name, int $baseCents): array
    {
        $label = strtoupper($name);
        (new Nfe55FederalStCalculator())->calculateGroup($tax,$name);
        $cst = trim((string) ($tax[$name.'_cst'] ?? ''));
        $rate = $tax[$name.'_rate'] ?? null;
        if (in_array($cst, ['04','05','06','07','08','09'], true)) {
            $this->assertNoTax($tax, $name, $label);
            return ['fields' => ['CST' => $cst], 'amount' => 0];
        }
        $mode=(string)($tax[$name.'_calculation_type']??'');
        if ($mode==='none' && in_array($cst,['49','99'],true) && $rate!==null && $rate!==''
            && NfeTaxMath::rate($rate,$label)===0) $mode='';
        if ($cst === '03' || (in_array($cst,['49','99'],true) && $mode==='quantity')) {
            if ($mode!=='' && $mode!=='quantity') throw new RuntimeException($label.': CST por quantidade incompatível com o modo de cálculo.');
            if ($rate !== null && $rate !== '' && NfeTaxMath::rate($rate, $label) !== 0) {
                throw new RuntimeException($label.': tributação por quantidade não aceita alíquota percentual.');
            }
            $quantity = NfeTaxMath::scaled($tax[$name.'_quantity'] ?? null, 4, $label.' quantidade tributável');
            if ($quantity <= 0) throw new RuntimeException($label.': quantidade tributável deve ser positiva.');
            $unitRate = NfeTaxMath::scaled($tax[$name.'_unit_rate'] ?? null, 4, $label.' alíquota por unidade');
            $amount = NfeTaxMath::product($quantity, $unitRate, 6);
            return ['fields' => [
                'CST' => $cst, 'qBCProd' => NfeTaxMath::format($quantity, 4),
                'vAliqProd' => NfeTaxMath::format($unitRate, 4), 'v'.$label => NfeTaxMath::format($amount),
            ], 'amount' => $amount];
        }
        if (in_array($cst, ['01','02','49','99'], true)) {
            if ($mode!=='' && $mode!=='percent') throw new RuntimeException($label.': CST percentual incompatível com o modo de cálculo.');
            foreach (['_quantity','_unit_rate'] as $suffix) {
                if (isset($tax[$name.$suffix]) && $tax[$name.$suffix]!==''
                    && NfeTaxMath::scaled($tax[$name.$suffix],4,$label)!==0) {
                    throw new RuntimeException($label.': parâmetros de quantidade não podem ser omitidos no cálculo percentual.');
                }
            }
            // Residual codes also need an explicit rate; omission is never an exemption.
            if (in_array($cst, ['49','99'], true)) {
                if ($rate === null || $rate === '') {
                    throw new RuntimeException($label.': CST residual exige alíquota explícita, inclusive quando zero.');
                }
                if (NfeTaxMath::rate($rate, $label) !== 0
                    && ($tax[$name.'_calculation_type'] ?? '') !== 'percent') {
                    throw new RuntimeException($label.': CST residual com alíquota exige modo percentual explícito.');
                }
                if (NfeTaxMath::rate($rate, $label) === 0 && !array_key_exists($name.'_base', $tax)) {
                    $baseCents = 0;
                }
            }
            $base = $this->base($tax, $name, $baseCents);
            $amount = NfeTaxMath::percent($base, $rate, $label.' alíquota');
            return ['fields' => [
                'CST' => $cst, 'vBC' => NfeTaxMath::format($base),
                'p'.$label => NfeTaxMath::format(NfeTaxMath::rate($rate, $label), 4),
                'v'.$label => NfeTaxMath::format($amount),
            ], 'amount' => $amount];
        }
        throw new RuntimeException($label.': CST sem cálculo implementado.');
    }

    private function ipi(array $tax, int $baseCents): array
    {
        $cst = trim((string) ($tax['ipi_cst'] ?? ''));
        $enq = trim((string) ($tax['ipi_enq'] ?? ''));
        if (!preg_match('/^\d{3}$/D', $enq)) throw new RuntimeException('IPI: informe o código de enquadramento legal.');
        $number=(int)$enq;
        foreach ([['01','04','54',99],['101','05','55',199],['301','02','52',399]] as [$start,$inbound,$outbound,$end]) {
            $inside=$number >= (int)$start && $number <= $end;
            $classified=in_array($cst,[$inbound,$outbound],true);
            if ($inside !== $classified) {
                throw new RuntimeException('IPI: CST incompatível com a faixa do enquadramento legal informado.');
            }
        }
        $fields = ['CST' => $cst, 'cEnq' => $enq];
        if (in_array($cst, ['01','02','03','04','05','51','52','53','54','55'], true)) {
            $this->assertNoTax($tax, 'ipi', 'IPI');
            return ['fields' => $fields, 'amount' => 0];
        }
        if (!in_array($cst, ['00','49','50','99'], true)) {
            throw new RuntimeException('IPI: CST sem cálculo implementado.');
        }
        $ipiMode=(string)($tax['ipi_calculation_type']??'');
        if ($ipiMode!=='' && !in_array($ipiMode,['percent','quantity'],true)) throw new RuntimeException('IPI: modo de cálculo inválido.');
        $quantityPresent=(isset($tax['ipi_quantity']) && $tax['ipi_quantity']!=='')
            || (isset($tax['ipi_unit_rate']) && $tax['ipi_unit_rate']!=='');
        if ($quantityPresent && (!isset($tax['ipi_quantity'],$tax['ipi_unit_rate'])
            || $tax['ipi_quantity']==='' || $tax['ipi_unit_rate']==='')) throw new RuntimeException('IPI: informe quantidade e valor por unidade juntos.');
        if ($ipiMode==='percent' && $quantityPresent) throw new RuntimeException('IPI: modo percentual incompatível com parâmetros de quantidade.');
        $quantityMode = $ipiMode === 'quantity'
            || (isset($tax['ipi_quantity'], $tax['ipi_unit_rate']) && $tax['ipi_quantity'] !== '' && $tax['ipi_unit_rate'] !== '');
        if ($quantityMode) {
            if (isset($tax['ipi_rate']) && $tax['ipi_rate'] !== '' && NfeTaxMath::rate($tax['ipi_rate'], 'IPI') !== 0) {
                throw new RuntimeException('IPI: escolha alíquota percentual ou por quantidade.');
            }
            $quantity = NfeTaxMath::scaled($tax['ipi_quantity'] ?? null, 4, 'IPI quantidade');
            if ($quantity <= 0) throw new RuntimeException('IPI: quantidade tributável deve ser positiva.');
            $unitRate = NfeTaxMath::scaled($tax['ipi_unit_rate'] ?? null, 4, 'IPI valor por unidade');
            $amount = NfeTaxMath::product($quantity, $unitRate, 6);
            $fields += ['qUnid' => NfeTaxMath::format($quantity, 4), 'vUnid' => NfeTaxMath::format($unitRate, 4)];
        } else {
            $base = $this->base($tax, 'ipi', $baseCents);
            $rate = $tax['ipi_rate'] ?? null;
            $amount = NfeTaxMath::percent($base, $rate, 'IPI alíquota');
            $fields += ['vBC' => NfeTaxMath::format($base), 'pIPI' => NfeTaxMath::format(NfeTaxMath::rate($rate, 'IPI'), 4)];
        }
        $fields['vIPI'] = NfeTaxMath::format($amount);
        return ['fields' => $fields, 'amount' => $amount];
    }

    private function base(array $tax, string $name, int $fallback): int
    {
        return isset($tax[$name.'_base']) && $tax[$name.'_base'] !== ''
            ? NfeTaxMath::scaled($tax[$name.'_base'], 2, strtoupper($name).' base') : $fallback;
    }

    private function assertNoTax(array $tax, string $name, string $label): void
    {
        if (!in_array((string)($tax[$name.'_calculation_type']??''),['','none'],true)) {
            throw new RuntimeException($label.': CST não tributado incompatível com modo de cálculo tributável.');
        }
        foreach (['_rate','_unit_rate','_quantity'] as $suffix) {
            if (isset($tax[$name.$suffix]) && $tax[$name.$suffix] !== ''
                && NfeTaxMath::scaled($tax[$name.$suffix], 4, $label) !== 0) {
                throw new RuntimeException($label.': CST não tributado incompatível com alíquota ou quantidade tributável.');
            }
        }
    }
}
