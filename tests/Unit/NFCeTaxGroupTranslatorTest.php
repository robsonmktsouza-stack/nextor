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

    public function test_rejects_service_profile_in_nfce(): void
    {
        $this->expectException(RuntimeException::class);
        (new NFCeTaxGroupTranslator())->translate($this->group(['kind'=>'services']));
    }
}
