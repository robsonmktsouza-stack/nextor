<?php

namespace Tests\Unit;

use App\Services\Fiscal\NFCeTaxCalculationService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NFCeTaxCalculationServiceTest extends TestCase
{
    private function item(array $tax = [], array $override = []): array
    {
        return array_replace([
            'origin' => '0',
            'quantity' => '2.000',
            'unit_price' => '50.00',
            'discount' => '10.00',
            'tax_defaults' => array_replace([
                'cfop' => '5102',
                'icms_csosn' => '102',
                'pis_cst' => '49',
                'cofins_cst' => '49',
            ], $tax),
        ], $override);
    }

    public function test_configured_simples_without_icms_amounts(): void
    {
        foreach (['102', '103', '300', '400'] as $csosn) {
            $result = (new NFCeTaxCalculationService())->calculate($this->item(['icms_csosn'=>$csosn]));
            self::assertSame($csosn, $result['icms']['CSOSN']);
            self::assertArrayNotHasKey('vICMS', $result['icms']);
            self::assertSame('0.00', $result['pis']['vPIS']);
        }
    }

    public function test_calculates_contributions_with_item_discount_and_two_decimal_rounding(): void
    {
        $result = (new NFCeTaxCalculationService())->calculate($this->item([
            'pis_cst'=>'01', 'pis_calc_type'=>'percentage', 'pis_rate'=>'1.65',
            'cofins_cst'=>'01', 'cofins_calc_type'=>'percentage', 'cofins_rate'=>'7.60',
        ]));
        self::assertSame('90.00', $result['pis']['vBC']);
        self::assertSame('1.65', $result['pis']['pPIS'] === '1.6500' ? '1.65' : $result['pis']['pPIS']);
        self::assertSame('1.49', $result['pis']['vPIS']);
        self::assertSame('6.84', $result['cofins']['vCOFINS']);
    }

    public function test_non_taxed_contribution_omits_base_and_rate(): void
    {
        $result = (new NFCeTaxCalculationService())->calculate($this->item([
            'pis_cst'=>'04',
            'cofins_cst'=>'06',
        ]));
        self::assertSame(['CST'=>'04'], $result['pis']);
        self::assertSame(['CST'=>'06'], $result['cofins']);
    }

    public function test_500_requires_cfop_and_actual_previously_retained_values(): void
    {
        $result = (new NFCeTaxCalculationService())->calculate($this->item([
            'cfop'=>'5405', 'icms_csosn'=>'500',
            'icms_st_retained_base'=>'45.00',
            'icms_st_retained_value'=>'8.10',
            'st_retained_amount_scope'=>'unit',
        ]));
        self::assertSame('500', $result['icms']['CSOSN']);
        self::assertSame('90.00', $result['icms']['vBCSTRet']);
        self::assertSame('16.20', $result['icms']['vICMSSTRet']);
    }

    public function test_line_scoped_retained_st_is_not_multiplied_by_quantity(): void
    {
        $result = (new NFCeTaxCalculationService())->calculate($this->item([
            'cfop'=>'5405', 'icms_csosn'=>'500',
            'icms_st_retained_base'=>'90.00',
            'icms_st_retained_value'=>'16.20',
            'st_retained_amount_scope'=>'line',
        ]));
        self::assertSame('90.00', $result['icms']['vBCSTRet']);
        self::assertSame('16.20', $result['icms']['vICMSSTRet']);
    }

    public function test_retained_st_without_scope_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('por unidade ou pelo item');
        (new NFCeTaxCalculationService())->calculate($this->item([
            'cfop'=>'5405', 'icms_csosn'=>'500',
            'icms_st_retained_base'=>'90.00',
            'icms_st_retained_value'=>'16.20',
        ]));
    }

    public function test_rejects_unconfigured_st_retention(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('icms_st_retained_base');
        (new NFCeTaxCalculationService())->calculate($this->item([
            'cfop'=>'5405', 'icms_csosn'=>'500', 'st_retained_amount_scope'=>'unit',
        ]));
    }

    public function test_rejects_incompatible_st_cfop(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CFOP');
        (new NFCeTaxCalculationService())->calculate($this->item([
            'icms_csosn'=>'500',
            'icms_st_retained_base'=>'100.00',
            'icms_st_retained_value'=>'18.00',
        ]));
    }

    public function test_rejects_pis_rate_without_compatible_calculation_mode(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Configure cálculo percentual');
        (new NFCeTaxCalculationService())->calculate($this->item([
            'pis_rate'=>'1.65',
        ]));
    }

    public function test_rejects_unimplemented_icms_classification(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ainda não possui cálculo');
        (new NFCeTaxCalculationService())->calculate($this->item([
            'icms_csosn'=>'201',
        ]));
    }

    public function test_rejects_tax_rate_in_a_non_taxed_pis_group(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('não admite');
        (new NFCeTaxCalculationService())->calculate($this->item([
            'pis_cst'=>'06','pis_rate'=>'1.65','pis_calc_type'=>'percentage',
        ]));
    }
    public function test_calculates_contributions_by_configured_quantity(): void
    {
        $result = (new NFCeTaxCalculationService())->calculate($this->item([
            'pis_cst'=>'03',
            'pis_calc_type'=>'quantity',
            'pis_quantity_rate'=>'0.1200',
            'cofins_cst'=>'03',
            'cofins_calc_type'=>'quantity',
            'cofins_quantity_rate'=>'0.5500',
        ]));
        self::assertSame('2.000', $result['pis']['qBCProd']);
        self::assertSame('0.1200', $result['pis']['vAliqProd']);
        self::assertSame('0.24', $result['pis']['vPIS']);
        self::assertSame('1.10', $result['cofins']['vCOFINS']);
    }

    public function test_rejects_quantity_mode_without_per_unit_rate(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('pis_quantity_rate');
        (new NFCeTaxCalculationService())->calculate($this->item([
            'pis_cst'=>'03', 'pis_calc_type'=>'quantity',
        ]));
    }

    public function test_crt_four_mei_mapping_is_restricted(): void
    {
        $calculator = new NFCeTaxCalculationService();
        self::assertSame('102', $calculator->calculate($this->item(), '4')['icms']['CSOSN']);
        self::assertSame('300', $calculator->calculate($this->item(['icms_csosn'=>'300']), '4')['icms']['CSOSN']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MEI');
        $calculator->calculate($this->item(['icms_csosn'=>'103']), '4');
    }

    public function test_normal_crt_three_calculates_icms_cst_00_and_20_from_configured_base(): void
    {
        $calculator = new NFCeTaxCalculationService();
        $tax = ['icms_cst'=>'00', 'mod_bc'=>'3', 'icms_rate'=>'18.00'];
        $normal = $calculator->calculate($this->item($tax), '3');
        self::assertSame('90.00', $normal['icms']['vBC']);
        self::assertSame('18.0000', $normal['icms']['pICMS']);
        self::assertSame('16.20', $normal['icms']['vICMS']);

        $reduced = $calculator->calculate($this->item(array_replace($tax, [
            'icms_cst'=>'20', 'base_reduction_rate'=>'20',
        ])), '3');
        self::assertSame('72.00', $reduced['icms']['vBC']);
        self::assertSame('20.0000', $reduced['icms']['pRedBC']);
        self::assertSame('12.96', $reduced['icms']['vICMS']);
    }

    public function test_normal_crt_rejects_unmapped_cst_or_wrong_base_modality(): void
    {
        $calculator = new NFCeTaxCalculationService();
        $item = $this->item(['icms_cst'=>'60', 'mod_bc'=>'3', 'icms_rate'=>'18']);
        try {
            $calculator->calculate($item, '3');
            self::fail('CST 60 cannot use the CST 00 formula');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('não possui cálculo', $exception->getMessage());
        }
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('modalidade de base 3');
        $calculator->calculate($this->item(['icms_cst'=>'00', 'mod_bc'=>'1', 'icms_rate'=>'18']), '3');
    }

    public function test_normal_icms_cst_40_41_have_no_icms_own_amounts(): void
    {
        foreach (['40','41'] as $cst) {
            $tax = (new NFCeTaxCalculationService())->calculate(
                $this->item(['icms_cst'=>$cst]), '3'
            );
            self::assertSame(['orig'=>'0','CST'=>$cst], $tax['icms']);
        }
    }

    public function test_normal_cst_40_requires_separate_mapping_for_desoneration(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('icms_desoneration_reason');
        (new NFCeTaxCalculationService())->calculate(
            $this->item(['icms_cst'=>'40','icms_desoneration_reason'=>'SUFRAMA']), '3'
        );
    }

}
