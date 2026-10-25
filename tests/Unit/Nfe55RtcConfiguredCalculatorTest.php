<?php

namespace Tests\Unit;

use App\Services\Fiscal\Nfe55RtcConfiguredCalculator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class Nfe55RtcConfiguredCalculatorTest extends TestCase
{
    private function parameters(string $cst = '000', string $classification = '000001'): array
    {
        return ['ibs_cbs_cst'=>$cst, 'ibs_cbs_class'=>$classification,
            'ibs_uf_rate'=>'0.1', 'ibs_municipal_rate'=>'0', 'cbs_rate'=>'0.9'];
    }

    private function reductions(string $percent = '60'): array
    {
        return ['ibs_uf_reduction_rate'=>$percent, 'ibs_municipal_reduction_rate'=>$percent, 'cbs_reduction_rate'=>$percent];
    }

    private function deferrals(): array
    {
        return ['ibs_uf_deferral_rate'=>'50', 'ibs_municipal_deferral_rate'=>'100', 'cbs_deferral_rate'=>'25'];
    }

    private function regular(): array
    {
        return ['rtc_regular_cst'=>'000', 'rtc_regular_class'=>'000001', 'rtc_regular_base'=>'100.00',
            'rtc_regular_ibs_uf_rate'=>'0.1', 'rtc_regular_ibs_municipal_rate'=>'0', 'rtc_regular_cbs_rate'=>'0.9',
            'rtc_regular_legal_source'=>'https://example.test/reviewed-rule',
            'rtc_regular_legal_reference'=>'Fixture revisada de hipótese sem a suspensão.'];
    }

    public function test_integral_tax_uses_the_final_base_once_and_reports_integer_totals(): void
    {
        $result = $this->calculate($this->parameters() + ['icms_rate'=>'18', 'pis_rate'=>'1.65']);
        self::assertSame(['CST'=>'000', 'cClassTrib'=>'000001'], $result['ibscbs']);
        self::assertSame(['vBC'=>'100.00', 'vIBS'=>'0.10'], $result['gibscbs']);
        self::assertSame(['pIBSUF'=>'0.1000', 'vIBSUF'=>'0.10'], $result['gibsuf']);
        self::assertSame(10000, $result['totals']['bc']);
        self::assertSame(10, $result['totals']['uf']);
        self::assertSame(90, $result['totals']['cbs']);
        self::assertSame(0, $result['totals']['dif_uf']);
        self::assertSame([], $result['extra_sections']);
    }

    public function test_export_non_incidence_only_serializes_the_classification(): void
    {
        $result = $this->calculate(['ibs_cbs_cst'=>'410', 'ibs_cbs_class'=>'410004']);
        self::assertSame(['CST'=>'410', 'cClassTrib'=>'410004'], $result['ibscbs']);
        foreach (['gibscbs','gibsuf','gibsmun','gcbs','extra_sections'] as $group) self::assertSame([], $result[$group]);
        self::assertSame(0, array_sum($result['totals']));
    }

    public function test_sixty_percent_reductions_follow_the_classification_for_each_jurisdiction(): void
    {
        $result = $this->calculate($this->parameters('200', '200033') + $this->reductions(), 12345);
        self::assertSame('60.0000', $result['gibsuf']['pRedAliq']);
        self::assertSame('0.0400', $result['gibsuf']['pAliqEfet']);
        self::assertSame('0.0000', $result['gibsmun']['pAliqEfet']);
        self::assertSame('0.3600', $result['gcbs']['pAliqEfet']);
        self::assertSame(5, $result['totals']['uf']);
        self::assertSame(44, $result['totals']['cbs']);
    }

    public function test_integral_reduction_still_requires_the_three_explicit_nominal_rates(): void
    {
        $tax = $this->parameters('200', '200003') + $this->reductions('100');
        $result = $this->calculate($tax);
        self::assertSame('0.1000', $result['gibsuf']['pIBSUF']);
        self::assertSame('0.0000', $result['gibsuf']['pAliqEfet']);
        self::assertSame(0, $result['totals']['uf']);
        foreach (['ibs_uf_rate','ibs_municipal_rate','cbs_rate'] as $field) {
            $incomplete = $tax;
            unset($incomplete[$field]);
            $this->assertRejected($incomplete);
        }
    }

    public function test_amounts_use_the_effective_rate_actually_serialized_with_four_decimal_places(): void
    {
        $result = $this->calculate(array_replace($this->parameters('200', '200033') + $this->reductions(), [
            'ibs_uf_rate'=>'0.1234',
        ]), 10000000);
        self::assertSame('0.0494', $result['gibsuf']['pAliqEfet']);
        self::assertSame(4940, $result['totals']['uf']);
    }

    public function test_deferral_subtracts_the_rounded_deferred_amount_from_full_item_tax(): void
    {
        $result = $this->calculate($this->parameters('510', '510001') + $this->deferrals(), 12345);
        self::assertSame('50.0000', $result['gibsuf']['pDif']);
        self::assertSame('0.06', $result['gibsuf']['vDif']);
        self::assertSame(6, $result['totals']['uf']);
        self::assertSame(6, $result['totals']['dif_uf']);
        self::assertSame(28, $result['totals']['dif_cbs']);
        self::assertSame(83, $result['totals']['cbs']);
    }

    public function test_reduction_and_deferral_are_applied_in_order_and_totalled_separately(): void
    {
        $result = $this->calculate($this->parameters('515', '515001') + $this->reductions() + $this->deferrals());
        self::assertSame('0.0400', $result['gibsuf']['pAliqEfet']);
        self::assertSame(2, $result['totals']['uf']);
        self::assertSame(2, $result['totals']['dif_uf']);
        self::assertSame(9, $result['totals']['dif_cbs']);
        self::assertSame(27, $result['totals']['cbs']);
    }

    public function test_official_reductions_cannot_be_changed_or_partially_omitted(): void
    {
        $tax = $this->parameters('200', '200033') + $this->reductions();
        $this->assertRejected(array_replace($tax, ['cbs_reduction_rate'=>'50']));
        foreach (array_keys($this->reductions()) as $field) {
            $incomplete = $tax;
            unset($incomplete[$field]);
            $this->assertRejected($incomplete);
        }
    }

    public function test_deferral_fields_are_explicit_and_positive_so_acbr_will_emit_the_required_group(): void
    {
        $tax = $this->parameters('510', '510001') + $this->deferrals();
        foreach (array_keys($this->deferrals()) as $field) {
            $incomplete = $tax;
            unset($incomplete[$field]);
            $this->assertRejected($incomplete);
            $this->assertRejected(array_replace($tax, [$field=>'0']));
            $this->assertRejected(array_replace($tax, [$field=>'100.0001']));
        }
    }

    public function test_suspension_requires_explicit_zero_own_rates_and_a_complete_approved_regular_group(): void
    {
        $tax = array_replace($this->parameters('550', '550001'), [
            'ibs_uf_rate'=>'0', 'ibs_municipal_rate'=>'0', 'cbs_rate'=>'0',
        ]) + $this->regular();
        $result = $this->calculate($tax);
        self::assertSame(0, $result['totals']['uf']);
        self::assertSame(0, $result['totals']['cbs']);
        self::assertSame('000', $result['extra_sections']['gTribRegular']['CSTReg']);
        self::assertSame('0.90', $result['extra_sections']['gTribRegular']['vTribRegCBS']);
        self::assertSame(10, $result['totals']['regular_uf']);
        self::assertSame(90, $result['totals']['regular_cbs']);
        $this->assertRejected(array_replace($tax, ['ibs_uf_rate'=>'0.1']));
        $this->assertRejected(array_replace($tax, ['rtc_regular_base'=>'99.99']));
        $this->assertRejected(array_replace($tax, ['rtc_regular_cst'=>'410', 'rtc_regular_class'=>'410004']));
        foreach (array_keys($this->regular()) as $field) {
            $incomplete = $tax;
            unset($incomplete[$field]);
            $this->assertRejected($incomplete);
        }
    }

    public function test_required_regular_tax_also_applies_to_a_conditional_zero_rate_class(): void
    {
        $tax = $this->parameters('200', '200022') + $this->reductions('100') + $this->regular();
        $result = $this->calculate($tax);
        self::assertSame('100.0000', $result['gcbs']['pRedAliq']);
        self::assertSame('0.90', $result['extra_sections']['gTribRegular']['vTribRegCBS']);
    }

    public function test_optional_groups_cannot_be_used_when_official_indicators_do_not_permit_them(): void
    {
        foreach ([['ibs_uf_reduction_rate'=>'0'], ['ibs_uf_deferral_rate'=>'0'],
            ['rtc_regular_cst'=>'000'], ['rtc_transfer_ibs'=>'0'], ['rtc_adjustment_ibs'=>'0'],
            ['ibs_uf_deferral_value'=>'1'], ['gDif'=>['pDif'=>'1']]] as $extra) {
            $this->assertRejected($this->parameters() + $extra);
        }
        $this->assertRejected($this->parameters('410', '410004'));
    }

    public function test_transferred_credit_is_explicit_and_never_fabricates_ordinary_tax_or_presumed_credit(): void
    {
        $tax = ['ibs_cbs_cst'=>'800', 'ibs_cbs_class'=>'800001', 'rtc_transfer_ibs'=>'12.34', 'rtc_transfer_cbs'=>'0'];
        $result = $this->calculate($tax);
        self::assertSame(['vIBS'=>'12.34', 'vCBS'=>'0.00'], $result['extra_sections']['gTransfCred']);
        self::assertSame([], $result['gibscbs']);
        self::assertSame(1234, $result['totals']['credit_ibs']);
        self::assertSame(0, $result['totals']['uf']);
        self::assertSame(0, $result['totals']['presumed_ibs']);
        unset($tax['rtc_transfer_cbs']);
        $this->assertRejected($tax);
    }

    public function test_competence_adjustment_preserves_its_explicit_period_and_values(): void
    {
        $tax = ['ibs_cbs_cst'=>'811', 'ibs_cbs_class'=>'811001',
            'rtc_adjustment_period'=>'2026-09', 'rtc_adjustment_ibs'=>'12.34', 'rtc_adjustment_cbs'=>'5.00'];
        $result = $this->calculate($tax);
        self::assertSame(['competApur'=>'2026-09', 'vIBS'=>'12.34', 'vCBS'=>'5.00'], $result['extra_sections']['gAjusteCompet']);
        self::assertSame(1234, $result['totals']['adjustment_ibs']);
        self::assertSame(500, $result['totals']['adjustment_cbs']);
        self::assertSame([], $result['gibsuf']);
        foreach (['2026-11','2026-13','09/2026','2024-12'] as $period) {
            $this->assertRejected(array_replace($tax, ['rtc_adjustment_period'=>$period]));
        }
    }

    public function test_classes_not_permitted_in_nfe_and_unimplemented_auxiliary_groups_remain_blocked(): void
    {
        foreach ([['010','010001'], ['011','011001'], ['222','222001'], ['400','400001'],
            ['620','620001'], ['200','200054']] as [$cst, $class]) $this->assertRejected($this->parameters($cst, $class));
        $this->assertRejected($this->parameters('000', '000003') + ['ibs_presumed_credit_rate'=>'0']);
    }

    public function test_invalid_bases_and_rates_fail_without_rounding_or_estimated_defaults(): void
    {
        foreach ([-1, 1000000000000000] as $base) {
            try {
                $this->calculate($this->parameters(), $base);
                self::fail('Base RTC inválida aceita.');
            } catch (RuntimeException $error) { self::assertStringContainsString('RTC:', $error->getMessage()); }
        }
        foreach (['-1','1.23456','1000'] as $rate) $this->assertRejected(array_replace($this->parameters(), ['ibs_uf_rate'=>$rate]));
    }

    private function calculate(array $tax, int $base = 10000): array
    {
        return (new Nfe55RtcConfiguredCalculator())->calculate($tax, $base, '2026-10-25');
    }

    private function assertRejected(array $tax): void
    {
        try {
            $this->calculate($tax);
            self::fail('Grupo RTC inválido aceito.');
        } catch (RuntimeException $error) { self::assertStringContainsString('RTC', $error->getMessage()); }
    }
}
