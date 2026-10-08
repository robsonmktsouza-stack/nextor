<?php

namespace Tests\Unit;

use App\Support\FiscalCodeCatalog;
use Tests\TestCase;

final class FiscalCodeCatalogTest extends TestCase
{
    public function test_shared_fiscal_catalog_preserves_leading_zero_codes(): void
    {
        $all=FiscalCodeCatalog::all();
        self::assertArrayHasKey('102',$all['csosn']);
        self::assertArrayHasKey('00',$all['icms_cst']);
        self::assertArrayHasKey('01',$all['pis_cofins_cst']);
        self::assertArrayHasKey('49',$all['pis_cofins_cst']);
        self::assertArrayHasKey('50',$all['ipi_cst']);
        self::assertArrayHasKey('0',$all['origin']);
        self::assertArrayHasKey('000',$all['ibs_cbs_cst']);
        self::assertArrayHasKey('000001',$all['ibs_cbs_class']);
        self::assertArrayHasKey('5102',$all['cfop']);
        self::assertArrayHasKey('x102',$all['cfop_pattern']);
    }

    public function test_field_names_resolve_to_same_catalog_across_erp(): void
    {
        $fields=[
            'icms_csosn'=>'csosn',
            'tax_defaults[icms_csosn_inbound]'=>'csosn',
            'icms_csosn_default'=>'csosn',
            'tax_config[ibs_cbs_cst]'=>'ibs_cbs_cst',
            'tax_defaults[tax_classification_code]'=>'ibs_cbs_class',
            'tax_config[ibs_cbs_class]'=>'ibs_cbs_class',
            'cofins_cst'=>'pis_cofins_cst',
            'tax_defaults[pis_cst_inbound]'=>'pis_cofins_cst',
            'tax_defaults[icms_cst]'=>'icms_cst',
            'tax_defaults[ipi_cst_inbound]'=>'ipi_cst',
            'tax_defaults[mod_bc_st]'=>'icms_mod_bc_st',
            'default_cfop'=>'cfop',
            'tax_config[state_variations][0][cfop]'=>'cfop',
            'cfop_pattern'=>'cfop_pattern',
            'tax_defaults[cfop_outbound_internal]'=>'cfop',
        ];
        foreach($fields as $field=>$expected) {
            self::assertSame($expected,FiscalCodeCatalog::forField($field),$field);
        }
        self::assertNull(FiscalCodeCatalog::forField('tax_defaults[cofins_rate]'));
        self::assertNull(FiscalCodeCatalog::forField('tax_config[cbs_rate]'));
    }

    public function test_cclasstrib_dataset_is_reference_only_and_not_fiscal_calculation(): void
    {
        $all=FiscalCodeCatalog::all();
        self::assertGreaterThan(100,count($all['ibs_cbs_class']));
        self::assertStringContainsString('integralmente',$all['ibs_cbs_class']['000001']);
    }
}
