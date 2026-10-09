<?php

namespace Tests\Unit;

use App\Models\FiscalTaxGroup;
use App\Services\Fiscal\NFCeTaxGroupTranslator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NFCeTaxGroupTranslatorTest extends TestCase
{
    private function group(array $override = []): FiscalTaxGroup
    {
        return new FiscalTaxGroup(array_replace([
            'name'=>'Mercadorias revisadas',
            'kind'=>'products',
            'is_active'=>true,
            'revision'=>3,
            'cfop_pattern'=>'x102',
            'icms_csosn'=>'101',
            'nfce_csosn'=>'102',
            'pis_cst'=>'49',
            'cofins_cst'=>'49',
            'tax_config'=>[],
        ],$override));
    }

    public function test_internal_nfce_uses_x_cfop_prefix_and_specific_csosn(): void
    {
        $group=$this->group();
        $group->id=7;
        $tax=(new NFCeTaxGroupTranslator())->translate($group);

        self::assertSame('5102',$tax['cfop_outbound_internal']);
        self::assertSame('5102',$tax['nfce_cfop']);
        self::assertSame('102',$tax['icms_csosn']);
        self::assertSame('49',$tax['pis_cst']);
        self::assertSame('49',$tax['cofins_cst']);
        self::assertSame(7,$tax['fiscal_group_id']);
        self::assertSame(3,$tax['fiscal_group_revision']);
    }

    public function test_rejects_non_implemented_icms_st_rate(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('não está implementado');
        (new NFCeTaxGroupTranslator())->translate($this->group(['tax_config'=>['icms_st_rate'=>'18']]));
    }

    public function test_rejects_non_emitted_ibs_classification(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ainda não são gerados');
        (new NFCeTaxGroupTranslator())->translate($this->group(['tax_config'=>['ibs_cbs_cst'=>'000']]));
    }

    public function test_rejects_icms_101_without_nfce_alternative(): void
    {
        $this->expectException(RuntimeException::class);
        (new NFCeTaxGroupTranslator())->translate($this->group(['nfce_csosn'=>null]));
    }

    public function test_rejects_inactive_profile(): void
    {
        $this->expectException(RuntimeException::class);
        (new NFCeTaxGroupTranslator())->translate($this->group(['is_active'=>false]));
    }

    public function test_rejects_anp_codes_not_emitted_in_nfce(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('anp_code');
        (new NFCeTaxGroupTranslator())->translate($this->group(['tax_config'=>['anp_code'=>'12345678']]));
    }

    public function test_rejects_cbenef_not_emitted_in_nfce(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fiscal_benefit_code');
        (new NFCeTaxGroupTranslator())->translate($this->group(['tax_config'=>['fiscal_benefit_code'=>'BA1234']]));
    }

    public function test_rejects_nonzero_deferral_rate(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('não está implementado');
        (new NFCeTaxGroupTranslator())->translate($this->group(['tax_config'=>['cbs_deferral_rate'=>0.2]]));
    }

    public function test_rejects_unimplemented_percentage_pis_method(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('pis_calc_type');
        (new NFCeTaxGroupTranslator())->translate($this->group(['tax_config'=>['pis_calc_type'=>'percentage']]));
    }

    public function test_rejects_municipal_overrides_not_emitted_in_nfce(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('variações municipais');
        (new NFCeTaxGroupTranslator())->translate($this->group([
            'tax_config'=>['municipal_variations'=>[['city_ibge'=>'2926806','rate'=>'0.1']]],
        ]));
    }

    public function test_rejects_service_profile_in_nfce(): void
    {
        $this->expectException(RuntimeException::class);
        (new NFCeTaxGroupTranslator())->translate($this->group(['kind'=>'services']));
    }
    public function test_normal_regime_group_maps_configured_cst_modality_and_icms_rate(): void
    {
        $group = $this->group([
            'icms_cst'=>'00',
            'tax_config'=>['mod_bc'=>'3','icms_rate'=>'18.00'],
        ]);
        $tax=(new NFCeTaxGroupTranslator())->translate($group,'3');
        self::assertSame('00',$tax['icms_cst']);
        self::assertSame('3',$tax['mod_bc']);
        self::assertSame('18.00',$tax['icms_rate']);
        self::assertArrayNotHasKey('icms_csosn',$tax);
    }

    public function test_configured_quantity_rates_are_translated_without_silent_zero(): void
    {
        $group = $this->group([
            'pis_cst'=>'03', 'cofins_cst'=>'03',
            'tax_config'=>[
                'pis_calc_type'=>'quantity', 'pis_quantity_rate'=>'0.1200',
                'cofins_calc_type'=>'quantity', 'cofins_quantity_rate'=>'0.5500',
            ],
        ]);
        $tax=(new NFCeTaxGroupTranslator())->translate($group);
        self::assertSame('quantity', $tax['pis_calc_type']);
        self::assertSame('0.1200', $tax['pis_quantity_rate']);
        self::assertSame('0.5500', $tax['cofins_quantity_rate']);
    }

}
