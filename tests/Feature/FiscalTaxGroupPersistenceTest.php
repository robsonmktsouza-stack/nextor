<?php

namespace Tests\Feature;

use App\Models\FiscalTaxGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class FiscalTaxGroupPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_has_optional_tax_group_links_and_preserves_legacy_group_field(): void
    {
        self::assertTrue(Schema::hasColumn('products','fiscal_tax_group_id'));
        self::assertTrue(Schema::hasColumn('services','fiscal_tax_group_id'));
        self::assertTrue(Schema::hasColumn('products','tax_group'));
        self::assertTrue(Schema::hasColumn('services','tax_group'));
    }

    public function test_tax_group_stores_config_and_revision_without_auto_activating(): void
    {
        $group=FiscalTaxGroup::query()->create([
            'name'=>'Grupo de teste',
            'kind'=>'products',
            'cfop_pattern'=>'x102',
            'icms_csosn'=>'101',
            'nfce_csosn'=>'102',
            'pis_cst'=>'49',
            'cofins_cst'=>'49',
            'tax_config'=>[
                'ibs_cbs_cst'=>'000',
                'ibs_cbs_class'=>'000001',
                'cbs_rate'=>0.9,
            ],
        ]);

        self::assertFalse($group->fresh()->is_active);
        self::assertFalse($group->fresh()->is_default);
        self::assertSame(1,$group->fresh()->revision);
        self::assertSame('x102',$group->fresh()->cfop_pattern);
        self::assertSame('000001',$group->fresh()->tax_config['ibs_cbs_class']);
    }
}
