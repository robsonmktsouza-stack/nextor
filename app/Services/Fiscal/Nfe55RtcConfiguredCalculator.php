<?php

namespace App\Services\Fiscal;

use RuntimeException;

/** Calculates only explicitly configured RTC groups admitted by the official NF-e classification. */
final class Nfe55RtcConfiguredCalculator
{
    private const PARTS = [
        'uf' => ['ibs_uf', 'gibsuf', 'pIBSUF', 'vIBSUF', 'IBSUF'],
        'mun' => ['ibs_municipal', 'gibsmun', 'pIBSMun', 'vIBSMun', 'IBSMun'],
        'cbs' => ['cbs', 'gcbs', 'pCBS', 'vCBS', 'CBS'],
    ];
    private readonly Nfe55RtcClassificationCatalog $catalog;

    public function __construct(?Nfe55RtcClassificationCatalog $catalog = null)
    {
        $this->catalog = $catalog ?? new Nfe55RtcClassificationCatalog();
    }

    /** baseCents is the final approved base: this method never subtracts legacy taxes again. */
    public function calculate(array $tax, int $baseCents, string $issueDate): array
    {
        $this->moneyLimit($baseCents);
        $cst = $this->code($tax['ibs_cbs_cst'] ?? null, 3);
        $classification = $this->code($tax['ibs_cbs_class'] ?? null, 6);
        $profile = $this->catalog->validate($cst, $classification, $issueDate);
        $flags = $profile['cst_flags'];
        if (!in_array($cst, ['000','010','011','200','222','400','410','510','515','550','800','811'], true)
            || $flags['IndMonofasica'] || $flags['IndCredPresIbsZfm']
            || $profile['flags']['IndEstornoCred'] || $profile['flags']['IndPbioDiferenca']) {
            throw new RuntimeException('RTC: classificação exige grupo específico ainda não implementado.');
        }
        $this->assertUnsupportedParameters($tax);
        $this->assertPermission($tax, 'rtc_regular_', $profile['flags']['IndTribRegular']);
        $this->assertPermission($tax, 'rtc_transfer_', $flags['IndTransferenciaCred']);
        $this->assertPermission($tax, 'rtc_adjustment_', $flags['IndAjusteCompet']);
        foreach (self::PARTS as [$prefix]) {
            $this->assertPermission($tax, $prefix.'_reduction_rate', $flags['IndReducaoAliq'], true);
            $this->assertPermission($tax, $prefix.'_deferral_rate', $flags['IndDiferimento'], true);
        }
        $result = [
            'ibscbs' => ['CST'=>$cst, 'cClassTrib'=>$classification],
            'gibscbs'=>[], 'gibsuf'=>[], 'gibsmun'=>[], 'gcbs'=>[], 'extra_sections'=>[],
            'totals'=>array_fill_keys(['bc','uf','mun','cbs','dif_uf','dif_mun','dif_cbs',
                'credit_ibs','credit_cbs','adjustment_ibs','adjustment_cbs',
                'regular_uf','regular_mun','regular_cbs','dev_uf','dev_mun','dev_cbs',
                'presumed_ibs','presumed_cbs','presumed_conditional_ibs','presumed_conditional_cbs'], 0),
            'classification'=>$profile,
        ];
        if (!$flags['IndExigeTrib']) {
            if ($profile['flags']['IndTribRegular']) {
                throw new RuntimeException('RTC: tributação regular sem grupo tributável exige tratamento específico.');
            }
            foreach (self::PARTS as [$prefix]) {
                if ($this->present($tax, $prefix.'_rate') && $this->rate($tax[$prefix.'_rate']) !== 0) {
                    throw new RuntimeException('RTC: classificação sem tributação própria não admite alíquota tributável.');
                }
            }
            if ($flags['IndTransferenciaCred']) {
                $ibs = $this->money($tax['rtc_transfer_ibs'] ?? null);
                $cbs = $this->money($tax['rtc_transfer_cbs'] ?? null);
                $result['extra_sections']['gTransfCred'] = ['vIBS'=>NfeTaxMath::format($ibs), 'vCBS'=>NfeTaxMath::format($cbs)];
                $result['totals']['credit_ibs'] = $ibs;
                $result['totals']['credit_cbs'] = $cbs;
            } elseif ($flags['IndAjusteCompet']) {
                $period = $tax['rtc_adjustment_period'] ?? null;
                if (!is_string($period) || !preg_match('/^\d{4}-(?:0[1-9]|1[0-2])$/D', $period)
                    || $period < '2025-01' || $period > substr($issueDate, 0, 7)) {
                    throw new RuntimeException('RTC: competência de ajuste ausente ou incompatível com a emissão.');
                }
                $ibs = $this->money($tax['rtc_adjustment_ibs'] ?? null);
                $cbs = $this->money($tax['rtc_adjustment_cbs'] ?? null);
                $result['extra_sections']['gAjusteCompet'] = ['competApur'=>$period,
                    'vIBS'=>NfeTaxMath::format($ibs), 'vCBS'=>NfeTaxMath::format($cbs)];
                $result['totals']['adjustment_ibs'] = $ibs;
                $result['totals']['adjustment_cbs'] = $cbs;
            }
            return $result;
        }
        if ($cst === '550' && !$profile['flags']['IndTribRegular']) {
            throw new RuntimeException('RTC: suspensão exige classificação com tributação regular expressamente indicada.');
        }
        if ($flags['IndReducaoBc'] && (!$this->present($tax, 'ibs_cbs_base')
            || $this->money($tax['ibs_cbs_base']) !== $baseCents)) {
            throw new RuntimeException('RTC: redução de base exige a base final explicitamente aprovada.');
        }
        $result['gibscbs'] = ['vBC'=>NfeTaxMath::format($baseCents)];
        $result['totals']['bc'] = $baseCents;
        foreach (self::PARTS as $name=>[$prefix, $group, $rateField, $valueField]) {
            $rate = $this->rate($tax[$prefix.'_rate'] ?? null);
            if ($cst === '550' && $rate !== 0) {
                throw new RuntimeException('RTC: suspensão exige alíquotas próprias explicitamente zero e tributação regular separada.');
            }
            $fields = [$rateField=>NfeTaxMath::format($rate, 4)];
            $effective = $rate;
            if ($flags['IndReducaoAliq']) {
                $reduction = NfeTaxMath::rate($tax[$prefix.'_reduction_rate'] ?? null, 'RTC redução explícita');
                $official = NfeTaxMath::rate($profile[$name === 'cbs' ? 'cbs_reduction' : 'ibs_reduction'], 'RTC redução oficial');
                if ($reduction !== $official) throw new RuntimeException('RTC: redução informada diverge da classificação oficial.');
                $effective = NfeTaxMath::product($rate, 1000000 - $reduction, 6);
                $fields += ['pRedAliq'=>NfeTaxMath::format($reduction, 4), 'pAliqEfet'=>NfeTaxMath::format($effective, 4)];
            }
            $full = NfeTaxMath::product($baseCents, $effective, 6);
            $this->moneyLimit($full);
            $deferred = 0;
            if ($flags['IndDiferimento']) {
                $deferral = NfeTaxMath::rate($tax[$prefix.'_deferral_rate'] ?? null, 'RTC diferimento explícito');
                if ($deferral === 0) throw new RuntimeException('RTC: grupo de diferimento exige percentual positivo para serialização ACBr.');
                $deferred = NfeTaxMath::product($full, $deferral, 6);
                $fields += ['pDif'=>NfeTaxMath::format($deferral, 4), 'vDif'=>NfeTaxMath::format($deferred)];
            }
            $value = $full - $deferred;
            $fields[$valueField] = NfeTaxMath::format($value);
            $result[$group] = $fields;
            $result['totals'][$name] = $value;
            $result['totals']['dif_'.$name] = $deferred;
        }
        $this->moneyLimit($result['totals']['uf'] + $result['totals']['mun']);
        $result['gibscbs']['vIBS'] = NfeTaxMath::format($result['totals']['uf'] + $result['totals']['mun']);
        if ($profile['flags']['IndTribRegular']) {
            $this->regularTax($tax, $baseCents, $issueDate, $result);
        }
        return $result;
    }

    private function regularTax(array $tax, int $base, string $date, array &$result): void
    {
        $cst = $this->code($tax['rtc_regular_cst'] ?? null, 3);
        $classification = $this->code($tax['rtc_regular_class'] ?? null, 6);
        $profile = $this->catalog->validate($cst, $classification, $date);
        if (!$profile['cst_flags']['IndExigeTrib'] || $profile['cst_flags']['IndDiferimento']
            || $profile['cst_flags']['IndMonofasica'] || $profile['flags']['IndTribRegular']
            || $profile['flags']['IndEstornoCred'] || $profile['flags']['IndPbioDiferenca']) {
            throw new RuntimeException('RTC: classificação da tributação regular exige tratamento específico.');
        }
        if ($this->money($tax['rtc_regular_base'] ?? null) !== $base) {
            throw new RuntimeException('RTC: base da tributação regular deve corresponder à base final serializada.');
        }
        $trace = is_array($tax['fiscal_rule_trace'] ?? null) ? $tax['fiscal_rule_trace'] : [];
        $source = $tax['rtc_regular_legal_source'] ?? $trace['legal_source'] ?? null;
        $reference = $tax['rtc_regular_legal_reference'] ?? $trace['legal_reference'] ?? null;
        if (!is_string($source) || !filter_var($source, FILTER_VALIDATE_URL)
            || parse_url($source, PHP_URL_SCHEME) !== 'https' || !is_string($reference) || trim($reference) === '') {
            throw new RuntimeException('RTC: tributação regular exige fonte e referência legal explícitas.');
        }
        $fields = ['CSTReg'=>$cst, 'cClassTribReg'=>$classification];
        foreach (self::PARTS as $name=>[$prefix, , , , $suffix]) {
            $rate = $this->rate($tax['rtc_regular_'.$prefix.'_rate'] ?? null);
            if ($profile['cst_flags']['IndReducaoAliq']
                && NfeTaxMath::rate($profile[$name === 'cbs' ? 'cbs_reduction' : 'ibs_reduction'], 'RTC redução oficial') === 1000000
                && $rate !== 0) {
                throw new RuntimeException('RTC: tributação regular com redução integral exige alíquota efetiva zero.');
            }
            $value = NfeTaxMath::product($base, $rate, 6);
            $this->moneyLimit($value);
            $fields['pAliqEfetReg'.$suffix] = NfeTaxMath::format($rate, 4);
            $fields['vTribReg'.$suffix] = NfeTaxMath::format($value);
            $result['totals']['regular_'.$name] = $value;
        }
        $result['extra_sections']['gTribRegular'] = $fields;
        $result['regular_approval'] = ['legal_source'=>$source, 'legal_reference'=>$reference];
    }

    private function assertPermission(array $tax, string $prefix, bool $allowed, bool $exact = false): void
    {
        if ($allowed) return;
        foreach ($tax as $key=>$value) {
            if ($value === null || $value === '' || str_ends_with((string)$key, '_inbound') || str_ends_with((string)$key, '_export')) continue;
            if ($exact ? $key === $prefix : str_starts_with((string)$key, $prefix)) {
                throw new RuntimeException('RTC: '.$key.' não é permitido pelos indicadores da classificação oficial.');
            }
        }
    }

    private function assertUnsupportedParameters(array $tax): void
    {
        $allowed = ['rtc_regular_cst','rtc_regular_class','rtc_regular_base','rtc_regular_ibs_uf_rate',
            'rtc_regular_ibs_municipal_rate','rtc_regular_cbs_rate','rtc_regular_legal_source','rtc_regular_legal_reference',
            'rtc_transfer_ibs','rtc_transfer_cbs','rtc_adjustment_period','rtc_adjustment_ibs','rtc_adjustment_cbs'];
        $rateGroups = [];
        foreach (self::PARTS as [$prefix]) {
            $rateGroups[] = $prefix.'_reduction_rate';
            $rateGroups[] = $prefix.'_deferral_rate';
        }
        foreach ($tax as $key=>$value) {
            $key = (string)$key;
            if ($value === null || $value === '' || str_ends_with($key, '_inbound') || str_ends_with($key, '_export')) continue;
            if ((str_starts_with($key, 'rtc_') && !in_array($key, $allowed, true))
                || preg_match('/^(?:ibs|cbs)_.*(?:credit|presum|mono|estorno|devol|return|regular|adjust|transfer|dev_trib)/i', $key)
                || (preg_match('/^(?:ibs|cbs)_.*(?:deferral|deferment|reduction|differ)/i', $key)
                    && !in_array($key, $rateGroups, true))
                || preg_match('/^g(?:IBS|CBS|Trib|Transf|Ajuste|Cred|Mono|Estorno|Dif|Red|DevTrib)/', $key)) {
                throw new RuntimeException('RTC: parâmetro '.$key.' exige grupo específico ainda não implementado.');
            }
        }
    }

    private function present(array $tax, string $key): bool
    {
        return array_key_exists($key, $tax) && $tax[$key] !== null && $tax[$key] !== '';
    }

    private function code(mixed $value, int $length): string
    {
        if (!is_string($value) || !preg_match('/^\d{'.$length.'}$/D', $value)) {
            throw new RuntimeException('RTC: classificação ou CST explícito ausente ou inválido.');
        }
        return $value;
    }

    private function rate(mixed $value): int
    {
        $rate = NfeTaxMath::scaled($value, 4, 'RTC alíquota explícita');
        if ($rate > 9999999) throw new RuntimeException('RTC: alíquota excede o domínio do leiaute.');
        return $rate;
    }

    private function money(mixed $value): int
    {
        $amount = NfeTaxMath::scaled($value, 2, 'RTC valor explícito');
        $this->moneyLimit($amount);
        return $amount;
    }

    private function moneyLimit(int $amount): void
    {
        if ($amount < 0 || $amount > 999999999999999) throw new RuntimeException('RTC: valor fora do limite do documento fiscal.');
    }
}
