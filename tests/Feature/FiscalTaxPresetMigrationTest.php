<?php

namespace Tests\Feature;

use App\Models\FiscalTaxGroup;
use App\Services\Fiscal\NFCeTaxGroupTranslator;
use App\Support\FiscalTaxPresetCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class FiscalTaxPresetMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_creates_all_official_code_based_preset_groups_without_auto_default(): void
    {
        self::assertTrue(Schema::hasColumn('fiscal_tax_groups', 'preset_key'));
        self::assertTrue(Schema::hasColumn('fiscal_tax_groups', 'target_crt'));
        $presets=FiscalTaxGroup::query()->whereNotNull('preset_key')->get();
        self::assertCount(count(FiscalTaxPresetCatalog::all()), $presets);
        self::assertSame(13, $presets->count());
        self::assertSame(0, FiscalTaxGroup::query()->where('is_default', true)->count());
        self::assertTrue($presets->every(fn(FiscalTaxGroup $group) =>
            $group->kind === 'products' && (int)$group->revision === 1
        ));
    }

    public function test_simple_retail_presets_are_usable_without_entering_all_tax_codes(): void
    {
        $regular=FiscalTaxGroup::query()->where('preset_key','sn_resale_common')->firstOrFail();
        self::assertTrue($regular->is_active);
        self::assertFalse($regular->is_default);
        $tax=app(NFCeTaxGroupTranslator::class)->translate($regular,'1');
        self::assertSame('5102',$tax['cfop_outbound_internal']);
        self::assertSame('102',$tax['icms_csosn']);
        self::assertSame('49',$tax['pis_cst']);

        $mono=FiscalTaxGroup::query()->where('preset_key','sn_resale_monophase')->firstOrFail();
        self::assertSame('04',app(NFCeTaxGroupTranslator::class)->translate($mono,'1')['pis_cst']);
        self::assertSame('04',app(NFCeTaxGroupTranslator::class)->translate($mono,'1')['cofins_cst']);
    }

    public function test_st_preset_does_not_prepopulate_retained_tax_amounts(): void
    {
        $group=FiscalTaxGroup::query()->where('preset_key','sn_resale_st_retained')->firstOrFail();
        $tax=app(NFCeTaxGroupTranslator::class)->translate($group,'1');
        self::assertSame('5405',$tax['cfop']);
        self::assertSame('500',$tax['icms_csosn']);
        self::assertArrayNotHasKey('icms_st_retained_value',$tax);
        self::assertArrayNotHasKey('st_retained_amount_scope',$tax);
    }

    public function test_other_regime_presets_cannot_be_used_by_simple_company(): void
    {
        $group=FiscalTaxGroup::query()->where('preset_key','mei_resale_common')->firstOrFail();
        self::assertSame('4',$group->target_crt);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exclusivo do CRT 4');
        app(NFCeTaxGroupTranslator::class)->translate($group,'1');
    }

    public function test_profiles_requiring_rates_or_special_legal_basis_start_inactive(): void
    {
        foreach (['normal_icms_00','normal_icms_20','excess_icms_00',
            'sn_immunity','sn_revenue_band_exemption','mei_immunity'] as $key) {
            self::assertFalse((bool)FiscalTaxGroup::query()
                ->where('preset_key',$key)->firstOrFail()->is_active);
        }
    }

    public function test_user_edited_preset_is_not_reapplied_or_turned_into_default(): void
    {
        $group=FiscalTaxGroup::query()->where('preset_key','sn_resale_common')->firstOrFail();
        $group->update(['name'=>'Revenda validada internamente', 'is_default'=>false, 'revision'=>2]);
        // O catálogo de definição não interfere no registro existente.
        self::assertSame('Revenda validada internamente', $group->fresh()->name);
        self::assertFalse($group->fresh()->is_default);
    }
}
