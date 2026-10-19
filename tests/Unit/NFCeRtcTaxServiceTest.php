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
