<?php

namespace App\Services\Fiscal;

use RuntimeException;

/** ICMS groups with explicit fiscal parameters. No rate, benefit or ST deduction is inferred. */
final class NfeIcmsItemTaxCalculator
{
    public function calculate(array $tax, int $baseCents, string $crt, ?string $quantity = null): array
    {
        $tax=Nfe55TaxParameters::forDirection($tax,'outbound');
        if ($baseCents < 0 || !in_array($crt, ['1','2','3','4'], true)) throw new RuntimeException('Base ou CRT do ICMS inválido.');
        $simple = in_array($crt, ['1','4'], true);
        $code = trim((string) ($tax[$simple ? 'icms_csosn' : 'icms_cst'] ?? ''));
        $supported = $simple ? ['101','102','103','201','202','203','300','400','500','900'] : ['00','10','20','30','40','41','50','51','60','70','90'];
        if ($crt === '4') $supported = ['102','300','400'];
        if (!in_array($code, $supported, true)) {
            throw new RuntimeException(($simple ? 'CSOSN ' : 'CST ').$code.' do ICMS sem cálculo implementado.');
        }
        $fields = [$simple ? 'CSOSN' : 'CST' => $code];
        $out = [
            'fields' => [], 'base_cents' => 0, 'icms_amount' => 0, 'st_base_cents' => 0,
            'st_amount' => 0, 'fcp_amount' => 0, 'fcp_st_amount' => 0, 'credit_amount' => 0,
            'desonerated_amount' => 0, 'total_deduction' => 0, 'fcp_st_retained_amount' => 0, 'total_addition' => 0,
        ];
        $residual = $code === ($simple ? '900' : '90');
        $desonKeys = ['icms_desoneration_amount','icms_desoneration_reason','icms_desoneration_deduct'];
        $creditGroup = $simple && (in_array($code, ['101','201'], true)
            || ($code === '900' && $this->anyPresent($tax, ['icms_credit_rate','simple_credit_rate'])));
        $residualOwn = $residual && ($this->anyPresent($tax, ['icms_rate','icms_base_mode','mod_bc',
            'icms_base_reduction','base_reduction_rate','fcp_rate','fcp_base'])
            || (!$creditGroup && $this->anyPresent($tax, ['icms_base'])));
        $residualSt = $residual && $this->anyPresent($tax, ['icms_st_base_mode','mod_bc_st',
            'icms_st_base','icms_st_rate','icms_st_mva','icms_st_deduction','icms_st_base_reduction','fcp_st_rate']);
        if ($residual) {
            if (!$residualOwn && !$residualSt && !$creditGroup && !(!$simple && $this->anyPresent($tax, $desonKeys))) {
                throw new RuntimeException('ICMS residual: informe explicitamente um grupo próprio, ST, crédito ou desoneração admitido.');
            }
            if ($simple && $this->anyPresent($tax, ['fcp_rate','fcp_base'])) {
                throw new RuntimeException('CSOSN 900 não admite FCP próprio; FCP-ST exige o grupo ST.');
            }
            if (!$simple && $this->anyPresent($tax, ['icms_credit_rate','simple_credit_rate'])) {
                throw new RuntimeException('CST 90 não admite crédito do Simples Nacional.');
            }
            if ($residualOwn && (!$this->anyPresent($tax, ['icms_base'])
                || !$this->anyPresent($tax, ['icms_rate']) || !$this->anyPresent($tax, ['icms_base_mode','mod_bc']))) {
                throw new RuntimeException('ICMS residual próprio exige base, alíquota e modalidade explícitas.');
            }
            if ($creditGroup && !$this->anyPresent($tax, ['icms_base'])) {
                throw new RuntimeException('Crédito do CSOSN 900 exige base explicitamente aprovada.');
            }
            foreach ($tax as $key=>$value) {
                if ($value !== null && $value !== '' && preg_match('/^icms_st_deson/i', (string)$key)) {
                    throw new RuntimeException('ICMS ST desonerado exige grupo específico ainda não implementado.');
                }
            }
        }
        $retained = in_array($code, ['60','500'], true);
        if ($retained) {
            $scope = (string)($tax['st_retained_amount_scope'] ?? '');
            if (!in_array($scope, ['line','unit'], true)) {
                throw new RuntimeException('ICMS ST anterior: informe se os valores documentados são por item ou unidade.');
            }
            $factor = $scope === 'unit' ? NfeTaxMath::scaled($quantity, 4, 'Quantidade da retenção') : 10000;
            if ($factor <= 0) throw new RuntimeException('Quantidade da retenção deve ser positiva.');
            $historical = static function(string $key) use ($tax,$scope,$factor): int {
                return $scope === 'unit'
                    ? NfeTaxMath::product(NfeTaxMath::scaled($tax[$key] ?? null, 4, $key), $factor, 6)
                    : NfeTaxMath::scaled($tax[$key] ?? null, 2, $key);
            };
            $fields += [
                'vBCSTRet'=>NfeTaxMath::format($historical('icms_st_retained_base')),
                'pST'=>NfeTaxMath::format(NfeTaxMath::rate($tax['st_retained_rate'] ?? null,'ICMS ST anterior alíquota'),4),
                'vICMSSubstituto'=>NfeTaxMath::format($historical('icms_substitutor_value')),
                'vICMSSTRet'=>NfeTaxMath::format($historical('icms_st_retained_value')),
            ];
            $fcpKeys=['fcp_st_retained_base','fcp_st_retained_rate','fcp_st_retained_value'];
            if ($this->anyPresent($tax,$fcpKeys)) {
                $out['fcp_st_retained_amount']=$historical('fcp_st_retained_value');
                $fields += ['vBCFCPSTRet'=>NfeTaxMath::format($historical('fcp_st_retained_base')),
                    'pFCPSTRet'=>NfeTaxMath::format(NfeTaxMath::rate($tax['fcp_st_retained_rate'] ?? null,'FCP ST anterior'),4),
                    'vFCPSTRet'=>NfeTaxMath::format($out['fcp_st_retained_amount'])];
            }
            $effective=['icms_effective_base','icms_effective_rate','icms_effective_value','icms_effective_reduction'];
            if ($this->anyPresent($tax,$effective)) {
                $fields += ['pRedBCEfet'=>NfeTaxMath::format(NfeTaxMath::rate($tax['icms_effective_reduction'] ?? null,'ICMS efetivo redução'),4),
                    'vBCEfet'=>NfeTaxMath::format($historical('icms_effective_base')),
                    'pICMSEfet'=>NfeTaxMath::format(NfeTaxMath::rate($tax['icms_effective_rate'] ?? null,'ICMS efetivo'),4),
                    'vICMSEfet'=>NfeTaxMath::format($historical('icms_effective_value'))];
            }
        } elseif ($this->anyPresent($tax,['st_retained_amount_scope','icms_st_retained_base','st_retained_rate',
            'icms_substitutor_value','icms_st_retained_value','fcp_st_retained_base','fcp_st_retained_rate',
            'fcp_st_retained_value','icms_effective_base','icms_effective_rate','icms_effective_value','icms_effective_reduction'])) {
            throw new RuntimeException('Retenção anterior exige CST 60 ou CSOSN 500.');
        }
        if ($this->anyPresent($tax,$desonKeys)) {
            if ($simple || !in_array($code,['40','41','50','90'],true)) {
                throw new RuntimeException('Desoneração parametrizada exige CST 40, 41, 50 ou 90 neste fluxo.');
            }
            $amount=NfeTaxMath::scaled($tax['icms_desoneration_amount'] ?? null,2,'ICMS desonerado');
            $reason=(string)($tax['icms_desoneration_reason'] ?? '');
            $reasons = $code === '90' ? ['3','9','12'] : ['1','3','4','5','6','7','8','9','10','11','12','16','90'];
            if (!in_array($reason,$reasons,true)) {
                throw new RuntimeException('Informe um motivo oficial de desoneração do ICMS.');
            }
            $deduct=(string)($tax['icms_desoneration_deduct'] ?? '');
            if (!in_array($deduct,['0','1'],true)) throw new RuntimeException('Informe explicitamente se o ICMS desonerado será deduzido do total.');
            if ($deduct === '1' && $amount > $baseCents) throw new RuntimeException('ICMS desonerado superior ao valor líquido do item.');
            $fields += ['vICMSDeson'=>NfeTaxMath::format($amount),'motDesICMS'=>$reason,'indDeduzDeson'=>$deduct];
            $out['desonerated_amount']=$amount;
            $out['total_deduction']=$deduct === '1' ? $amount : 0;
        }
        if ($code !== '51' && isset($tax['icms_deferment_rate']) && $tax['icms_deferment_rate'] !== ''
            && NfeTaxMath::rate($tax['icms_deferment_rate'],'ICMS diferimento') !== 0) {
            throw new RuntimeException('Diferimento do ICMS exige CST 51.');
        }
        $ownTax = (!$simple && in_array($code, ['00','10','20','51','70'], true)) || $residualOwn;
        if ($ownTax) {
            $mode = (string) ($tax['icms_base_mode'] ?? $tax['mod_bc'] ?? '3');
            if (!in_array($mode, ['0','1','2','3'], true)) throw new RuntimeException('ICMS: modalidade de base inválida.');
            if ($mode !== '3' && (!isset($tax['icms_base']) || $tax['icms_base'] === '')) {
                throw new RuntimeException('ICMS: esta modalidade exige base expressamente parametrizada.');
            }
            $base = isset($tax['icms_base']) && $tax['icms_base'] !== ''
                ? NfeTaxMath::scaled($tax['icms_base'], 2, 'ICMS base') : $baseCents;
            $reduced = in_array($code, ['20','70'], true)
                || ($residual && $this->anyPresent($tax, ['icms_base_reduction','base_reduction_rate']));
            $reduction = $tax['icms_base_reduction'] ?? $tax['base_reduction_rate'] ?? ($code === '51' ? '0' : null);
            if ($reduced || ($code === '51' && $reduction !== null)) {
                $scaledReduction = NfeTaxMath::rate($reduction, 'ICMS redução da base');
                $base = NfeTaxMath::product($base, 1000000 - $scaledReduction, 6);
                if (!$residual || $scaledReduction !== 0) $fields['pRedBC'] = NfeTaxMath::format($scaledReduction, 4);
            } elseif ($reduction !== null && $reduction !== '' && NfeTaxMath::rate($reduction, 'ICMS redução') !== 0) {
                throw new RuntimeException('ICMS: CST informado não comporta redução da base.');
            }
            $rate = $tax['icms_rate'] ?? null;
            $amount = NfeTaxMath::percent($base, $rate, 'ICMS alíquota');
            $fields += ['modBC' => $mode, 'vBC' => NfeTaxMath::format($base),
                'pICMS' => NfeTaxMath::format(NfeTaxMath::rate($rate, 'ICMS'), 4)];
            if ($code === '51') {
                $deferment = NfeTaxMath::rate($tax['icms_deferment_rate'] ?? null, 'ICMS diferimento');
                $deferred = NfeTaxMath::product($amount, $deferment, 6);
                $fields += ['vICMSOp' => NfeTaxMath::format($amount),
                    'pDif' => NfeTaxMath::format($deferment, 4), 'vICMSDif' => NfeTaxMath::format($deferred)];
                $amount -= $deferred;
            }
            $fields['vICMS'] = NfeTaxMath::format($amount);
            $out['base_cents'] = $base;
            $out['icms_amount'] = $amount;
            if (isset($tax['fcp_rate']) && $tax['fcp_rate'] !== '') {
                $fcpRate = NfeTaxMath::rate($tax['fcp_rate'], 'FCP');
                $fcpBase = isset($tax['fcp_base']) && $tax['fcp_base'] !== ''
                    ? NfeTaxMath::scaled($tax['fcp_base'], 2, 'FCP base') : $base;
                if ($code === '00' && $fcpBase !== $base) throw new RuntimeException('FCP: CST 00 exige a mesma base do ICMS.');
                $out['fcp_amount'] = NfeTaxMath::product($fcpBase, $fcpRate, 6);
                if ($fcpRate !== 0) {
                    if ($code !== '00') $fields['vBCFCP'] = NfeTaxMath::format($fcpBase);
                    $fields += ['pFCP' => NfeTaxMath::format($fcpRate, 4), 'vFCP' => NfeTaxMath::format($out['fcp_amount'])];
                }
            }
        } else {
            foreach (['icms_rate','fcp_rate','icms_base_reduction','icms_deferment_rate'] as $key) {
                if (isset($tax[$key]) && $tax[$key] !== '' && NfeTaxMath::rate($tax[$key], 'ICMS') !== 0) {
                    throw new RuntimeException('ICMS: CST/CSOSN sem débito próprio incompatível com alíquota ou redução.');
                }
            }
        }
        if ($creditGroup) {
            $creditRate = $tax['icms_credit_rate'] ?? $tax['simple_credit_rate'] ?? null;
            $creditBase=isset($tax['icms_base']) && $tax['icms_base'] !== ''
                ? NfeTaxMath::scaled($tax['icms_base'],2,'ICMS base do crédito') : $baseCents;
            $out['credit_amount'] = NfeTaxMath::percent($creditBase, $creditRate, 'Crédito do Simples Nacional');
            $fields += ['pCredSN' => NfeTaxMath::format(NfeTaxMath::rate($creditRate, 'Crédito do Simples Nacional'), 4),
                'vCredICMSSN' => NfeTaxMath::format($out['credit_amount'])];
        }
        if (!$creditGroup) {
            foreach (['icms_credit_rate','simple_credit_rate'] as $key) {
                if (isset($tax[$key]) && $tax[$key] !== '' && NfeTaxMath::rate($tax[$key], 'Crédito ICMS') !== 0) {
                    throw new RuntimeException('ICMS: o código informado não admite o crédito do Simples Nacional parametrizado.');
                }
            }
        }
        $st = in_array($code, ['10','30','70','201','202','203'], true) || $residualSt;
        if ($st) {
            $mode = (string) ($tax['icms_st_base_mode'] ?? $tax['mod_bc_st'] ?? '');
            if (!in_array($mode, ['0','1','2','3','4','5','6'], true)) throw new RuntimeException('ICMS ST: informe a modalidade da base.');
            $base = NfeTaxMath::scaled($tax['icms_st_base'] ?? null, 2, 'ICMS ST base');
            if ($mode === '4') {
                $mva = NfeTaxMath::scaled($tax['icms_st_mva'] ?? null, 4, 'ICMS ST margem de valor agregado');
                if ($mva > 10000000) throw new RuntimeException('ICMS ST: MVA superior ao limite de 1000%.');
                $base = NfeTaxMath::product($base, 1000000 + $mva, 6);
                if ($mva !== 0) $fields['pMVAST'] = NfeTaxMath::format($mva, 4);
            }
            if ($mode !== '4' && isset($tax['icms_st_mva']) && $tax['icms_st_mva'] !== ''
                && NfeTaxMath::scaled($tax['icms_st_mva'],4,'MVA') !== 0) {
                throw new RuntimeException('ICMS ST: margem de valor agregado exige modalidade de base 4.');
            }
            $reduction = $tax['icms_st_base_reduction'] ?? '0';
            $scaledReduction = NfeTaxMath::rate($reduction, 'ICMS ST redução');
            $base = NfeTaxMath::product($base, 1000000 - $scaledReduction, 6);
            $stRate = $tax['icms_st_rate'] ?? null;
            $amount = NfeTaxMath::percent($base, $stRate, 'ICMS ST alíquota');
            $deduction = NfeTaxMath::scaled($tax['icms_st_deduction'] ?? null, 2, 'ICMS ST dedução do imposto próprio');
            if ($deduction > $amount) throw new RuntimeException('ICMS ST: dedução superior ao imposto calculado.');
            if ((!$simple || ($residual && $residualOwn)) && $deduction !== $out['icms_amount']) {
                throw new RuntimeException('ICMS ST: a dedução deve corresponder ao ICMS próprio calculado no item.');
            }
            $out['st_base_cents'] = $base;
            $out['st_amount'] = $amount - $deduction;
            $fields += ['modBCST' => $mode,
                'vBCST' => NfeTaxMath::format($base), 'pICMSST' => NfeTaxMath::format(NfeTaxMath::rate($stRate, 'ICMS ST'), 4),
                'vICMSST' => NfeTaxMath::format($out['st_amount'])];
            if ($scaledReduction !== 0) $fields['pRedBCST'] = NfeTaxMath::format($scaledReduction, 4);
            if (isset($tax['fcp_st_rate']) && $tax['fcp_st_rate'] !== '') {
                $fcpRate = NfeTaxMath::rate($tax['fcp_st_rate'], 'FCP ST');
                $out['fcp_st_amount'] = NfeTaxMath::product($base, $fcpRate, 6);
                if ($fcpRate !== 0) {
                    $fields += ['vBCFCPST' => NfeTaxMath::format($base), 'pFCPST' => NfeTaxMath::format($fcpRate, 4),
                        'vFCPST' => NfeTaxMath::format($out['fcp_st_amount'])];
                }
            }
        } else {
            foreach (['icms_st_base','icms_st_rate','fcp_st_rate','icms_st_mva','icms_st_deduction','icms_st_base_reduction'] as $key) {
                if (isset($tax[$key]) && $tax[$key] !== '' && NfeTaxMath::scaled($tax[$key], 4, 'ICMS ST') !== 0) {
                    throw new RuntimeException('ICMS: CST/CSOSN incompatível com substituição tributária parametrizada.');
                }
            }
        }
        if ($residual) {
            foreach ($fields as $field=>$value) {
                if (str_starts_with($field, 'v') && NfeTaxMath::scaled($value, 2, 'ICMS residual '.$field) > 999999999999999) {
                    throw new RuntimeException('ICMS residual: valor excede o limite do documento fiscal.');
                }
            }
        }
        $out['fields'] = $fields;
        $out['total_addition'] = $out['st_amount'] + $out['fcp_st_amount'];
        return $out;
    }

    private function anyPresent(array $tax,array $fields): bool
    {
        foreach ($fields as $field) {
            if (array_key_exists($field,$tax) && $tax[$field] !== null && $tax[$field] !== '') return true;
        }
        return false;
    }
}
