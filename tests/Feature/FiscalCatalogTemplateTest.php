<?php

namespace Tests\Feature;

use App\Models\FiscalTaxGroup;
use App\Models\OperationNature;
use App\Models\User;
use App\Services\Fiscal\FiscalCatalogTemplateService;
use App\Services\Fiscal\NFCeTaxGroupTranslator;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class FiscalCatalogTemplateTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\WithIsolatedFiscalCatalog;
    use \Tests\Concerns\WithConfiguredCompany { setUp as protected setUpConfiguredCompany; }

    protected function setUp(): void
    {
        $this->setUpConfiguredCompany();
        $this->isolateFiscalCatalog();
    }

    private function install(string $version='2026.10.26'): array
    {
        return app(FiscalCatalogTemplateService::class)->install($version);
    }

    private function admin(): User
    {
        return User::query()->create(['name'=>'Revisor fiscal','email'=>'catalog-review@example.test',
            'password'=>'senhaTeste123','role'=>'admin','is_active'=>true]);
    }

    private function approval(): array
    {
        return ['fiscal_review_confirmed'=>'1','fiscal_review_legal_reference'=>'Fundamento específico da operação revisado em teste',
            'fiscal_review_source'=>'https://www.planalto.gov.br/ccivil_03/leis/lcp/lcp123.htm',
            'fiscal_review_conditions'=>'Apenas revenda confirmada; ICMS-ST e tratamentos específicos excluídos.',
            'fiscal_review_valid_from'=>'2026-10-01'];
    }

    public function test_catalog_models_are_installed_idempotently_in_existing_tables_and_start_unavailable(): void
    {
        $legacy=FiscalTaxGroup::query()->where('preset_key','sn_resale_common')->firstOrFail()->attributesToArray();
        $first=$this->install();
        $again=$this->install();
        self::assertSame(16,$first['natures']['inserted']);
        self::assertSame(13,$first['groups']['inserted']);
        self::assertSame(0,$again['natures']['inserted']);
        self::assertSame(0,$again['natures']['updated']);
        self::assertSame(0,$again['groups']['updated']);
        self::assertSame(16,OperationNature::query()->whereNotNull('catalog_version')->count());
        self::assertSame(0,OperationNature::query()->whereNotNull('catalog_version')->where('is_active',true)->count());
        self::assertSame(0,FiscalTaxGroup::query()->whereNotNull('catalog_version')->where('is_active',true)->count());
        self::assertSame(0,FiscalTaxGroup::query()->whereNotNull('catalog_version')->where('is_default',true)->count());
        self::assertTrue(OperationNature::query()->whereNotNull('catalog_version')->get()->every(fn($nature)=>mb_strlen($nature->name)<=60));
        self::assertSame($legacy,FiscalTaxGroup::query()->where('preset_key','sn_resale_common')->firstOrFail()->attributesToArray());
        self::assertSame(16,DB::table('fiscal_operation_nature_revisions')->count());
        self::assertSame(13,DB::table('fiscal_tax_group_revisions')->count());
    }

    public function test_templates_distinguish_contributor_non_contributor_returns_and_repair_directions(): void
    {
        $this->install();
        $contributor=OperationNature::query()->where('preset_key','nfe55_resale_contributor')->firstOrFail();
        $nonContributor=OperationNature::query()->where('preset_key','nfe55_resale_non_contributor')->firstOrFail();
        self::assertSame('6102',$contributor->cfop_interstate);
        self::assertSame(['contributor','exempt'],data_get($contributor->catalog_metadata,'required_context.recipient_profile'));
        self::assertSame('6108',$nonContributor->cfop_interstate);
        self::assertSame(['non_contributor'],data_get($nonContributor->catalog_metadata,'required_context.recipient_profile'));
        $return=OperationNature::query()->where('preset_key','nfe55_return_sale_resale')->firstOrFail();
        self::assertSame('inbound',$return->operation_type);
        self::assertSame('return',$return->purpose);
        self::assertSame('1202',$return->cfop_inbound_internal);
        self::assertNull($return->cfop_internal);
        $repair=OperationNature::query()->where('preset_key','nfe55_repair_return_outbound')->firstOrFail();
        self::assertSame('5916',$repair->cfop_internal);
        self::assertFalse($repair->generate_finance);
        self::assertSame(['return_of_remittance'],data_get($repair->catalog_metadata,'required_context.fiscal_operation'));
    }

    public function test_template_groups_do_not_supply_rates_benefits_or_presume_st_or_difal(): void
    {
        $this->install();
        foreach (FiscalTaxGroup::query()->whereNotNull('catalog_version')->get() as $group) {
            self::assertSame([],$group->tax_config);
            self::assertNull($group->cfop_pattern);
            self::assertSame('template',$group->catalog_status);
            self::assertFalse(data_get($group->catalog_metadata,'automatic_application'));
        }
        self::assertNull(FiscalTaxGroup::query()->where('preset_key','nfe55_template_interstate')->firstOrFail()->icms_csosn);
        self::assertNull(FiscalTaxGroup::query()->where('preset_key','nfe55_template_sn_st_substitute')->firstOrFail()->icms_csosn);
    }

    public function test_catalog_update_changes_only_untouched_disabled_models_and_preserves_customized_models(): void
    {
        $this->install();
        $nature=OperationNature::query()->where('preset_key','nfe55_purchase_resale')->firstOrFail();
        $nature->update(['additional_info'=>'Instrução específica desta empresa']);
        $group=FiscalTaxGroup::query()->where('preset_key','nfe55_template_sn_resale')->firstOrFail();
        $group->update(['name'=>'Grupo personalizado da empresa','tax_config'=>['icms_rate'=>'17.5000']]);
        $counts=$this->install('2026.11.01');
        self::assertSame(15,$counts['natures']['updated']);
        self::assertSame(12,$counts['groups']['updated']);
        self::assertSame('2026.10.26',$nature->fresh()->catalog_version);
        self::assertSame('Instrução específica desta empresa',$nature->fresh()->additional_info);
        self::assertSame('Grupo personalizado da empresa',$group->fresh()->name);
        self::assertSame(['icms_rate'=>'17.5000'],$group->fresh()->tax_config);
        self::assertSame(2,OperationNature::query()->where('preset_key','nfe55_resale_contributor')->firstOrFail()->revision);
    }

    public function test_catalog_nature_cannot_be_activated_without_explicit_fiscal_review(): void
    {
        $this->install();
        $nature=OperationNature::query()->where('preset_key','nfe55_resale_contributor')->firstOrFail();
        $data=['name'=>$nature->name,'operation_type'=>'outbound','purpose'=>'normal','cfop_internal'=>'5102',
            'cfop_interstate'=>'6102','is_active'=>'1','override_product_cfop'=>'1','move_stock'=>'1'];
        $this->actingAs($this->admin())->put(route('fiscal.nfe.natures.update',$nature),$data)
            ->assertSessionHasErrors('fiscal_review_confirmed');
        self::assertFalse($nature->fresh()->is_active);
        self::assertSame(1,$nature->fresh()->revision);
    }

    public function test_authorized_review_is_audited_and_later_catalog_updates_do_not_change_the_approved_nature(): void
    {
        $this->install();
        $nature=OperationNature::query()->where('preset_key','nfe55_resale_contributor')->firstOrFail();
        $admin=$this->admin();
        $data=['name'=>$nature->name,'operation_type'=>'outbound','purpose'=>'normal','cfop_internal'=>'5102',
            'cfop_interstate'=>'6102','is_active'=>'1','override_product_cfop'=>'1','move_stock'=>'1'];
        $this->actingAs($admin)->put(route('fiscal.nfe.natures.update',$nature),$data+$this->approval())
            ->assertSessionHasNoErrors()->assertRedirect();
        $nature->refresh();
        self::assertTrue($nature->is_active);
        self::assertSame('reviewed',$nature->catalog_status);
        self::assertSame('not_applicable',$nature->presence_default);
        self::assertSame($admin->id,$nature->fiscal_reviewed_by);
        self::assertSame('2026-10-01',data_get($nature->catalog_metadata,'review.valid_from'));
        self::assertSame(2,$nature->revisions()->count());
        self::assertFalse($nature->revisions()->where('revision',1)->firstOrFail()->snapshot['is_active']);
        $this->install('2026.11.01');
        self::assertSame('2026.10.26',$nature->fresh()->catalog_version);
        self::assertSame(2,$nature->fresh()->revision);
        $this->get(route('fiscal.nfe.natures.index'))->assertOk()->assertSee('Histórico de alterações')->assertSee('fiscal_review_confirmed',false);
    }

    public function test_group_review_is_explicit_and_existing_group_controller_records_revisions(): void
    {
        $this->install();
        $group=FiscalTaxGroup::query()->where('preset_key','nfe55_template_sn_resale')->firstOrFail();
        $data=['name'=>$group->name,'kind'=>'products','cfop_pattern'=>'5102','icms_csosn'=>'102',
            'pis_cst'=>'49','cofins_cst'=>'49','is_active'=>'1'];
        $this->actingAs($this->admin())->put(route('fiscal.tax-groups.update',$group),$data)
            ->assertSessionHasErrors('fiscal_review_confirmed');
        self::assertFalse($group->fresh()->is_active);
        $this->put(route('fiscal.tax-groups.update',$group),$data+$this->approval())
            ->assertSessionHasNoErrors()->assertRedirect();
        self::assertSame('reviewed',$group->fresh()->catalog_status);
        self::assertSame(2,$group->revisions()->count());
        self::assertSame('102',$group->revisions()->where('revision',2)->firstOrFail()->snapshot['icms_csosn']);
        $this->get(route('fiscal.tax-groups.edit',$group))->assertOk()->assertSee('Histórico de alterações');
    }

    public function test_review_requires_an_official_source_and_valid_period(): void
    {
        $this->install();
        $nature=OperationNature::query()->where('preset_key','nfe55_resale_contributor')->firstOrFail();
        $data=['name'=>$nature->name,'operation_type'=>'outbound','purpose'=>'normal','is_active'=>'1'];
        $approval=array_replace($this->approval(),['fiscal_review_source'=>'https://example.com/forum']);
        $this->actingAs($this->admin())->put(route('fiscal.nfe.natures.update',$nature),$data+$approval)
            ->assertSessionHasErrors('fiscal_review_source');
        $approval=array_replace($this->approval(),['fiscal_review_valid_until'=>'2026-09-30']);
        $this->put(route('fiscal.nfe.natures.update',$nature),$data+$approval)
            ->assertSessionHasErrors('fiscal_review_valid_until');
    }

    public function test_inactive_model_forged_active_in_storage_still_cannot_be_translated_as_a_reviewed_nfce_group(): void
    {
        $this->install();
        $group=FiscalTaxGroup::query()->where('preset_key','nfe55_template_sn_resale')->firstOrFail();
        $group->update(['is_active'=>true,'cfop_pattern'=>'5102','icms_csosn'=>'102','pis_cst'=>'49','cofins_cst'=>'49']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('pendente de revisão');
        app(NFCeTaxGroupTranslator::class)->translate($group,'1','BA');
    }

    public function test_reviewed_template_outside_its_period_cannot_be_applied_to_nfce(): void
    {
        $this->install();
        $group=FiscalTaxGroup::query()->where('preset_key','nfe55_template_sn_resale')->firstOrFail();
        $metadata=$group->catalog_metadata;
        $metadata['review']=['legal_reference'=>'Revisão de teste',
            'source'=>'https://www.planalto.gov.br/ccivil_03/leis/lcp/lcp123.htm',
            'valid_from'=>now()->addDay()->toDateString(),'valid_until'=>null];
        $group->update(['is_active'=>true,'catalog_status'=>'reviewed','catalog_metadata'=>$metadata,
            'cfop_pattern'=>'5102','icms_csosn'=>'102','pis_cst'=>'49','cofins_cst'=>'49']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('fora da vigência');
        app(NFCeTaxGroupTranslator::class)->translate($group,'1','BA');
    }

    public function test_revision_migrations_are_reversible_and_preserve_parent_records(): void
    {
        $migration=require database_path('migrations/2026_10_26_000002_add_catalog_metadata_to_fiscal_models.php');
        self::assertTrue(Schema::hasColumn('operation_natures','catalog_metadata'));
        self::assertTrue(Schema::hasTable('fiscal_tax_group_revisions'));
        $legacyCount=FiscalTaxGroup::query()->count();
        $migration->down();
        self::assertFalse(Schema::hasColumn('operation_natures','catalog_version'));
        self::assertFalse(Schema::hasTable('fiscal_operation_nature_revisions'));
        self::assertSame($legacyCount,FiscalTaxGroup::query()->count());
        $migration->up();
        self::assertTrue(Schema::hasColumn('operation_natures','catalog_status'));
        self::assertSame($legacyCount,FiscalTaxGroup::query()->count());
    }

    public function test_revision_foreign_key_rejects_another_or_missing_parent(): void
    {
        $this->expectException(QueryException::class);
        DB::table('fiscal_operation_nature_revisions')->insert(['operation_nature_id'=>999999,
            'revision'=>1,'snapshot'=>'{}','created_at'=>now()]);
    }
}
