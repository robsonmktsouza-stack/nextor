<?php

namespace Tests\Unit;

use App\Services\Fiscal\NFCeRtcTaxService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NFCeRtcTaxServiceTest extends TestCase
{
    private function item(array $tax): array
    {
        return [
            'quantity'=>'2.000',
            'unit_price'=>'50.00',
            'discount'=>'10.00',
            'tax_defaults'=>$tax,
        ];
    }

    private function regular(array $override=[]): array
    {
        return $this->item(array_replace([
            'ibs_cbs_cst'=>'000',
            'ibs_cbs_class'=>'000001',
            'ibs_uf_rate'=>'0.1000',
            'ibs_municipal_rate'=>'0.0000',
            'cbs_rate'=>'0.9000',
        ],$override));
    }

    public function test_regular_000001_calculates_correct_cent_amounts(): void
    {
        $actual=(new NFCeRtcTaxService())->calculate($this->regular(),'3','2026-10-19');
        self::assertSame(['CST'=>'000','cClassTrib'=>'000001'],$actual['ibscbs']);
        self::assertSame(['vBC'=>'90.00','vIBS'=>'0.09'],$actual['gibscbs']);
        self::assertSame(['pIBSUF'=>'0.1000','vIBSUF'=>'0.09'],$actual['gibsuf']);
        self::assertSame(['pIBSMun'=>'0.0000','vIBSMun'=>'0.00'],$actual['gibsmun']);
        self::assertSame(['pCBS'=>'0.9000','vCBS'=>'0.81'],$actual['gcbs']);
    }

    public function test_rtc_base_excludes_icms_pis_cofins_during_the_transition(): void
    {
        $legacy=[
            'icms'=>['vICMS'=>'16.20'],
            'pis'=>['vPIS'=>'1.49'],
            'cofins'=>['vCOFINS'=>'6.84'],
        ];
        $result=(new NFCeRtcTaxService())->calculate(
            $this->regular(),'3','2026-10-19',$legacy
        );
        self::assertSame('65.47',$result['gibscbs']['vBC']);
        self::assertSame('0.07',$result['gibsuf']['vIBSUF']);
        self::assertSame('0.59',$result['gcbs']['vCBS']);
    }

    public function test_rtc_base_does_not_exclude_legacy_taxes_after_2032(): void
    {
        $legacy=['icms'=>['vICMS'=>'16.20']];
        $result=(new NFCeRtcTaxService())->calculate(
            $this->regular(),'3','2033-01-01',$legacy
        );
        self::assertSame('90.00',$result['gibscbs']['vBC']);
    }

    public function test_rtc_refuses_negative_base_when_legacy_taxes_exceed_sale(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('excedem o valor líquido');
        (new NFCeRtcTaxService())->calculate(
            $this->regular(),'3','2026-10-19',
            ['icms'=>['vICMS'=>'91.00']]
        );
    }

    public function test_rates_are_not_presumed_for_blank_configuration(): void
    {
        self::assertNull((new NFCeRtcTaxService())->calculate(
            $this->item([]),'1','2026-10-19'
        ));
    }

    public function test_normal_regime_without_rtc_is_blocked_even_if_sefaz_rejection_was_deferred(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('regime normal exige classificação');
        (new NFCeRtcTaxService())->calculate($this->item([]),'3','2026-10-19');
    }

    public function test_reduced_cst_200003_generates_all_three_100_percent_zero_rates(): void
    {
        $item=$this->regular([
            'ibs_cbs_cst'=>'200','ibs_cbs_class'=>'200003',
            'ibs_uf_reduction_rate'=>'100',
            'ibs_municipal_reduction_rate'=>'100',
            'cbs_reduction_rate'=>'100',
        ]);
        $result=(new NFCeRtcTaxService())->calculate($item,'3','2026-10-19');
        self::assertSame('200',$result['ibscbs']['CST']);
        self::assertSame('200003',$result['ibscbs']['cClassTrib']);
        foreach (['gibsuf','gibsmun','gcbs'] as $part) {
            self::assertSame('100.0000',$result[$part]['pRedAliq']);
            self::assertSame('0.0000',$result[$part]['pAliqEfet']);
        }
        self::assertSame('90.00',$result['gibscbs']['vBC']);
        self::assertSame('0.00',$result['gibscbs']['vIBS']);
        self::assertSame('0.00',$result['gcbs']['vCBS']);
    }

    public function test_reduction_without_authorized_rate_or_with_wrong_class_fails(): void
    {
        $item=$this->regular([
            'ibs_cbs_cst'=>'200','ibs_cbs_class'=>'200003',
            'ibs_uf_reduction_rate'=>'60',
            'ibs_municipal_reduction_rate'=>'100',
            'cbs_reduction_rate'=>'100',
        ]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ibs_uf_reduction_rate');
        (new NFCeRtcTaxService())->calculate($item,'3','2026-10-19');
    }

    public function test_unmapped_reduced_class_is_not_treated_as_food(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sem mapeamento específico');
        (new NFCeRtcTaxService())->calculate($this->regular([
            'ibs_cbs_cst'=>'200','ibs_cbs_class'=>'200001',
            'ibs_uf_reduction_rate'=>'100','ibs_municipal_reduction_rate'=>'100','cbs_reduction_rate'=>'100',
        ]),'3','2026-10-19');
    }

    public function test_partial_rate_configuration_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ibs_municipal_rate');
        (new NFCeRtcTaxService())->calculate($this->regular(['ibs_municipal_rate'=>'']),'3','2026-10-19');
    }

    public function test_other_cst_cannot_be_taxed_using_integral_formula(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CST 000');
        (new NFCeRtcTaxService())->calculate($this->regular(['ibs_cbs_cst'=>'200']),'3','2026-10-19');
    }

    public function test_selective_tax_cst_without_integral_rtc_must_not_disappear(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Imposto Seletivo');
        (new NFCeRtcTaxService())->calculate($this->item(['is_cst'=>'000']),'1','2026-10-19');
    }

    public function test_reduction_without_standard_rtc_fields_must_be_blocked(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cbs_reduction_rate');
        (new NFCeRtcTaxService())->calculate($this->item(['cbs_reduction_rate'=>'5']),'1','2026-10-19');
    }

    public function test_reduction_cannot_be_ignored(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cbs_reduction_rate');
        (new NFCeRtcTaxService())->calculate($this->regular(['cbs_reduction_rate'=>'10']),'3','2026-10-19');
    }

    public function test_simples_regime_regular_cannot_be_assumed_in_2026(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Simples Nacional');
        (new NFCeRtcTaxService())->calculate($this->regular(),'1','2026-10-19');
    }

    public function test_simples_requires_tax_profile_configuration_from_2027(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('2027');
        (new NFCeRtcTaxService())->calculate($this->item([]),'1','2027-01-01');
    }

    public function test_ibs_is_sum_of_state_and_municipal_parts(): void
    {
        $actual=(new NFCeRtcTaxService())->calculate(
            $this->regular(['ibs_municipal_rate'=>'0.2500']),
            '3','2026-10-19'
        );
        self::assertSame('0.23',$actual['gibsmun']['vIBSMun']);
        self::assertSame('0.32',$actual['gibscbs']['vIBS']);
    }

    public function test_cannot_exceed_sale_gross_with_discount(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('desconto maior');
        (new NFCeRtcTaxService())->calculate(
            array_replace($this->regular(), ['discount'=>'110.00']), '3', '2026-10-19'
        );
    }
}
