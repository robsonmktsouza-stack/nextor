<?php

namespace Tests\Feature;

use App\Models\CompanySetting;
use App\Models\FiscalTaxGroup;
use App\Models\FiscalTaxRule;
use App\Models\NfeDraft;
use App\Models\NfeDraftItem;
use App\Models\OperationNature;
use App\Models\Product;
use App\Models\User;
use App\Services\Fiscal\FiscalCatalogService;
use App\Services\Fiscal\FiscalTaxRuleResolver;
use App\Services\Fiscal\Nfe55TaxRuleApplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\Concerns\WithConfiguredCompany;
use Tests\Concerns\WithIsolatedFiscalCatalog;
use Tests\TestCase;

final class FiscalCatalogDeterminationTest extends TestCase
{
    use RefreshDatabase, WithIsolatedFiscalCatalog;
    use WithConfiguredCompany { setUp as private setUpConfiguredCompany; }

    protected function setUp(): void
    {
        $this->setUpConfiguredCompany();
        $this->isolateFiscalCatalog();
    }

    private function install(): void
    {
        $this->travelTo(Carbon::parse('2026-10-26 12:00:00'));
        CompanySetting::current()->update(['crt'=>'1','tax_regime'=>'simples_nacional','city_ibge_code'=>'2927408']);
        app(FiscalCatalogService::class)->import([
            'schema_version'=>1,'version'=>'determination-test-1','consulted_at'=>'2026-10-26',
            'sources'=>[['id'=>'fixture','url'=>'https://www.confaz.fazenda.gov.br/legislacao',
                'reference'=>'Fixture isolada; não é definição fiscal de produção.','consulted_at'=>'2026-10-26']],
            'coverage'=>['cfop'=>'partial','ncm'=>'partial'],
            'entries'=>array_map(fn ($code)=>['kind'=>'cfop','code'=>$code,'description'=>'CFOP de teste '.$code,'source_id'=>'fixture'],
                ['5102','6102','6108','1202','2202','5405']) + [
                    6=>['kind'=>'ncm','code'=>'61091000','description'=>'Camisetas de algodão — fixture','source_id'=>'fixture'],
                ],
        ]);
    }

    private function group(array $extra = []): FiscalTaxGroup
    {
        return FiscalTaxGroup::query()->create(array_replace([
            'name'=>'Grupo revisado da instalação','kind'=>'products','is_active'=>true,'is_default'=>false,
            'catalog_version'=>'determination-test-1','catalog_status'=>'reviewed','fiscal_reviewed_at'=>now(),
            'catalog_metadata'=>['required_context'=>['product_usage_type'=>['resale']]],
        ], $extra));
    }

    private function nature(array $extra = []): OperationNature
    {
        return OperationNature::query()->create(array_replace([
            'name'=>'Venda revisada','operation_type'=>'outbound','purpose'=>'normal','is_active'=>true,
            'catalog_version'=>'determination-test-1','catalog_status'=>'reviewed','fiscal_reviewed_at'=>now(),
            'catalog_metadata'=>['required_context'=>['destination'=>['internal','interstate'],'fiscal_operation'=>['sale']]],
        ], $extra));
    }

    private function rule(array $extra = []): FiscalTaxRule
    {
        return FiscalTaxRule::query()->create(array_replace([
            'name'=>'Determinação revisada','document_type'=>'nfe','origin_uf'=>'BA','destination_uf'=>'SP',
            'crt'=>'1','cfop'=>'6102','csosn'=>'102','pis_cst'=>'49','cofins_cst'=>'49','priority'=>0,
            'operation_type'=>'outbound','purpose'=>'normal','recipient_profile'=>'contributor',
            'valid_from'=>'2026-01-01','valid_until'=>'2026-12-31','is_active'=>true,
            'legal_source'=>'https://www.confaz.fazenda.gov.br/legislacao','legal_reference'=>'Fixture, sem enquadramento real',
            'layout_version'=>'fixture-1','consulted_at'=>'2026-10-26',
            'catalog_version'=>'determination-test-1','catalog_status'=>'reviewed','fiscal_reviewed_at'=>now(),
        ], $extra));
    }

    /** @return array{NfeDraft, Product, FiscalTaxGroup, OperationNature} */
    private function draft(array $extra = []): array
    {
        $group=$this->group(); $nature=$this->nature();
        $product=Product::query()->create(['name'=>'Produto sem inferência de enquadramento','sku'=>'TESTE','unit'=>'UN',
            'usage_type'=>'resale','ncm'=>'61091000','origin'=>'0','fiscal_tax_group_id'=>$group->id,
            'tax_defaults'=>['cfop_outbound_internal'=>'5102']]);
        $draft=NfeDraft::query()->create(array_replace([
            'operation_nature_id'=>$nature->id,'status'=>'draft','operation_type'=>'outbound','purpose'=>'normal',
            'destination'=>'interstate','issue_date'=>'2026-10-26','recipient_snapshot'=>['state'=>'SP',
                'ie_indicator'=>'contributor','city_ibge_code'=>'3550308'],'final_consumer'=>false,
        ], $extra));
        $draft->items()->create(['product_id'=>$product->id,'item_number'=>1,'product_name'=>$product->name,
            'quantity'=>'1.0000','unit_price'=>'100.0000','line_total'=>'100.00','cfop'=>'5102',
            'ncm'=>'61091000','origin'=>'0','tax_data'=>[]]);
        $draft->load('items');
        return [$draft,$product,$group,$nature];
    }

    public function test_determination_considers_group_nature_municipality_and_icms_indicator(): void
    {
        $this->install(); [$draft,$product,$group,$nature]=$this->draft();
        $rule=$this->rule(['fiscal_tax_group_id'=>$group->id,'operation_nature_id'=>$nature->id,
            'origin_municipality'=>'2927408','destination_municipality'=>'3550308','recipient_icms_indicator'=>'1']);
        $before=$product->fresh()->getAttributes();
        app(Nfe55TaxRuleApplicationService::class)->apply($draft,CompanySetting::current());
        $item=$draft->items->first();
        self::assertSame('6102',$item->cfop);
        self::assertSame('102',$item->tax_data['icms_csosn']);
        self::assertSame($rule->id,$item->tax_data['fiscal_rule_trace']['id']);
        self::assertSame($group->id,$item->tax_data['fiscal_rule_trace']['determination_context']['fiscal_tax_group_id']);
        self::assertSame('determination-test-1',$item->tax_data['fiscal_rule_trace']['catalog_version']);
        self::assertSame('5102',$item->tax_data['fiscal_rule_trace']['input_context']['cfop']);
        self::assertSame($before,$product->fresh()->getAttributes());
        app(Nfe55TaxRuleApplicationService::class)->assertCurrent($draft,CompanySetting::current());
    }

    public function test_missing_rule_preserves_an_incomplete_draft_and_blocks_real_builder_guard(): void
    {
        $this->install(); [$draft]=$this->draft();
        $service=app(Nfe55TaxRuleApplicationService::class);
        $service->apply($draft,CompanySetting::current(),true,true);
        self::assertStringContainsString('nenhuma regra fiscal',$draft->items->first()->tax_data['fiscal_determination_error']);
        self::assertNull($draft->fresh()->document_number);
        self::assertSame('draft',$draft->fresh()->status);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('nenhuma regra fiscal');
        $service->assertCurrent($draft,CompanySetting::current());
    }

    public function test_unreviewed_template_cannot_be_applied_even_when_enabled_by_direct_database_write(): void
    {
        $this->install(); [$draft,$product,$group]=$this->draft();
        $group->update(['catalog_status'=>'template','fiscal_reviewed_at'=>null]);
        $this->rule();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('precisa ser revisado');
        app(Nfe55TaxRuleApplicationService::class)->apply($draft,CompanySetting::current(),false);
    }

    public function test_review_validity_and_template_conditions_are_enforced(): void
    {
        $this->install(); [$draft,$product,$group,$nature]=$this->draft();
        $nature->update(['catalog_metadata'=>['review'=>['valid_from'=>'2027-01-01']]]);
        $this->rule();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fora da vigência');
        app(Nfe55TaxRuleApplicationService::class)->apply($draft,CompanySetting::current(),false);
    }

    public function test_noncontributor_has_own_cfop_and_final_consumer_rule(): void
    {
        $this->install(); [$draft]=$this->draft(['recipient_snapshot'=>['state'=>'SP','ie_indicator'=>'non_contributor'],'final_consumer'=>true]);
        $this->rule(['recipient_profile'=>'contributor','cfop'=>'6102']);
        $rule=$this->rule(['recipient_profile'=>'non_contributor','recipient_icms_indicator'=>'9','final_consumer'=>true,'cfop'=>'6108']);
        app(Nfe55TaxRuleApplicationService::class)->apply($draft,CompanySetting::current(),false);
        self::assertSame('6108',$draft->items->first()->cfop);
        self::assertSame($rule->id,$draft->items->first()->tax_data['fiscal_rule_trace']['id']);
        $draft->final_consumer=false;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('nenhuma regra fiscal');
        app(Nfe55TaxRuleApplicationService::class)->apply($draft,CompanySetting::current(),false);
    }

    public function test_internal_and_return_contexts_use_distinct_rules_without_mutating_product(): void
    {
        $this->install(); [$draft,$product,$group,$nature]=$this->draft(['recipient_snapshot'=>['state'=>'BA','ie_indicator'=>'contributor'],'destination'=>'internal']);
        $this->rule();
        $internal=$this->rule(['destination_uf'=>'BA','cfop'=>'5102']);
        $service=app(Nfe55TaxRuleApplicationService::class);
        $service->apply($draft,CompanySetting::current(),false);
        self::assertSame($internal->id,$draft->items->first()->tax_data['fiscal_rule_trace']['id']);
        $nature->update(['operation_type'=>'inbound','purpose'=>'return','catalog_metadata'=>[
            'required_context'=>['operation_type'=>['inbound'],'purpose'=>['return'],'fiscal_operation'=>['devolution']]]]);
        $draft->operation_type='inbound'; $draft->purpose='return';
        $return=$this->rule(['destination_uf'=>'BA','cfop'=>'1202','operation_type'=>'inbound','purpose'=>'return']);
        $service->apply($draft,CompanySetting::current(),false);
        self::assertSame('1202',$draft->items->first()->cfop);
        self::assertSame($return->id,$draft->items->first()->tax_data['fiscal_rule_trace']['id']);
        self::assertSame('5102',$product->fresh()->tax_defaults['cfop_outbound_internal']);
    }

    public function test_managed_tax_exceptions_require_a_separately_reviewed_rule(): void
    {
        $this->install(); [$draft]=$this->draft();
        $this->rule(['tax_parameters'=>['icms_credit_rate'=>'1.0000']]);
        $draft->items->first()->tax_data=['icms_credit_rate'=>'2.0000'];
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exceção fiscal revisada');
        app(Nfe55TaxRuleApplicationService::class)->apply($draft,CompanySetting::current(),false);
    }

    public function test_new_context_rank_selects_specific_group_and_rejects_missing_context(): void
    {
        $this->install(); $group=$this->group(); $nature=$this->nature();
        $general=$this->rule();
        $specific=$this->rule(['fiscal_tax_group_id'=>$group->id,'operation_nature_id'=>$nature->id]);
        $resolver=app(FiscalTaxRuleResolver::class);
        $context=['operation_type'=>'outbound','purpose'=>'normal','recipient_profile'=>'contributor',
            'operation_nature_id'=>$nature->id,'fiscal_tax_group_id'=>$group->id];
        self::assertSame($specific->id,$resolver->resolve('nfe','BA','SP','1',$context,'2026-10-26')->id);
        unset($context['fiscal_tax_group_id']);
        self::assertSame($general->id,$resolver->resolve('nfe','BA','SP','1',$context,'2026-10-26')->id);
    }

    public function test_catalog_templates_never_compete_with_reviewed_rules(): void
    {
        $this->install();
        $this->rule(['catalog_status'=>'template','priority'=>99]);
        $reviewed=$this->rule();
        $match=app(FiscalTaxRuleResolver::class)->resolve('nfe','BA','SP','1',[
            'operation_type'=>'outbound','purpose'=>'normal','recipient_profile'=>'contributor'],'2026-10-26');
        self::assertSame($reviewed->id,$match->id);
    }

    public function test_foreign_issuer_cannot_use_installation_catalog_rules(): void
    {
        $this->install(); [$draft]=$this->draft(); $this->rule();
        config(['instance.cnpj'=>'11111111000191']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('outra instalação/CNPJ');
        app(Nfe55TaxRuleApplicationService::class)->apply($draft,CompanySetting::current(),false);
    }

    public function test_managed_rule_review_is_authorized_recorded_and_cannot_be_removed_on_update(): void
    {
        $this->install(); $group=$this->group();
        $admin=User::query()->create(['name'=>'Revisor','email'=>'review-rule@example.test','password'=>'test-password','role'=>'admin','is_active'=>true]);
        $payload=$this->rule()->only(['name','document_type','origin_uf','destination_uf','crt','cfop','csosn',
            'pis_cst','cofins_cst','priority','operation_type','purpose','recipient_profile','legal_source','legal_reference',
            'layout_version','catalog_version','catalog_status']);
        FiscalTaxRule::query()->delete();
        $payload += ['fiscal_tax_group_id'=>$group->id,'is_active'=>true,'valid_from'=>'2026-01-01','consulted_at'=>'2026-10-26'];
        $this->actingAs($admin)->post(route('fiscal.tax-rules.store'),$payload)->assertSessionHasErrors('fiscal_review_confirmed');
        $payload['fiscal_review_confirmed']=true;
        $this->post(route('fiscal.tax-rules.store'),$payload)->assertSessionHasNoErrors();
        $rule=FiscalTaxRule::query()->firstOrFail();
        self::assertSame($admin->id,$rule->fiscal_reviewed_by);
        self::assertNotNull($rule->fiscal_reviewed_at);
        unset($payload['fiscal_review_confirmed'],$payload['catalog_version'],$payload['catalog_status'],$payload['fiscal_tax_group_id']);
        $payload['revision']=$rule->revision;
        $this->put(route('fiscal.tax-rules.update',$rule),$payload)->assertSessionHasErrors('fiscal_review_confirmed');
        self::assertSame('determination-test-1',$rule->fresh()->catalog_version);
        self::assertSame(1,$rule->fresh()->revision);
    }

    public function test_inactive_or_expired_reviewed_rule_does_not_supply_automatic_taxation(): void
    {
        $this->install(); [$draft]=$this->draft();
        $this->rule(['valid_until'=>'2026-10-25']);
        $this->rule(['is_active'=>false]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('nenhuma regra fiscal');
        app(Nfe55TaxRuleApplicationService::class)->assertCurrent($draft,CompanySetting::current());
    }

    public function test_st_and_difal_parameters_are_taken_only_from_reviewed_rules(): void
    {
        $this->install(); [$draft]=$this->draft();
        $parameters=['icms_st_rate'=>'12.0000','icms_st_base_mode'=>'4','icms_st_mva'=>'20.0000',
            'difal_internal_rate'=>'18.0000','difal_interstate_rate'=>'12.0000','difal_share_rate'=>'100.0000'];
        $this->rule(['tax_parameters'=>$parameters]);
        app(Nfe55TaxRuleApplicationService::class)->apply($draft,CompanySetting::current(),false);
        foreach ($parameters as $field=>$value) self::assertSame($value,$draft->items->first()->tax_data[$field]);
        self::assertArrayNotHasKey('fcp_rate',$draft->items->first()->tax_data);
    }
    public function test_contributor_exempt_from_registration_uses_own_indicator_without_becoming_noncontributor(): void
    {
        $this->install(); [$draft]=$this->draft(['recipient_snapshot'=>['state'=>'SP','ie_indicator'=>'exempt']]);
        $rule=$this->rule(['recipient_profile'=>'exempt','recipient_icms_indicator'=>'2','cfop'=>'6102']);
        app(Nfe55TaxRuleApplicationService::class)->apply($draft,CompanySetting::current(),false);
        self::assertSame('6102',$draft->items->first()->cfop);
        self::assertSame($rule->id,$draft->items->first()->tax_data['fiscal_rule_trace']['id']);
        self::assertSame('2',$draft->items->first()->tax_data['fiscal_rule_trace']['determination_context']['recipient_icms_indicator']);
    }

    public function test_normal_regime_uses_reviewed_cst_instead_of_simples_code(): void
    {
        $this->install(); [$draft]=$this->draft();
        $company=CompanySetting::current(); $company->crt='3'; $company->tax_regime='lucro_real';
        $this->rule(['crt'=>'3','csosn'=>'','icms_cst'=>'00']);
        app(Nfe55TaxRuleApplicationService::class)->apply($draft,$company,false);
        self::assertSame('00',$draft->items->first()->tax_data['icms_cst']);
        self::assertArrayNotHasKey('icms_csosn',$draft->items->first()->tax_data);
    }

    public function test_missing_official_cfop_reference_never_falls_back_to_a_tax_rule_code(): void
    {
        $this->install(); [$draft]=$this->draft();
        $this->rule(['cfop'=>'6999']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('catálogo oficial instalado');
        app(Nfe55TaxRuleApplicationService::class)->apply($draft,CompanySetting::current(),false);
    }

    public function test_unaudited_managed_rule_cannot_be_used_through_database_activation(): void
    {
        $this->install(); [$draft]=$this->draft();
        $this->rule(['fiscal_reviewed_at'=>null]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ainda não foi revisada');
        app(Nfe55TaxRuleApplicationService::class)->apply($draft,CompanySetting::current(),false);
    }

    public function test_equal_priority_overlapping_context_still_reports_a_conflict(): void
    {
        $this->install(); $group=$this->group();
        $first=$this->rule(['fiscal_tax_group_id'=>$group->id]);
        $second=$this->rule(['fiscal_tax_group_id'=>$group->id,'name'=>'Conflito da instalação']);
        self::assertContains($first->name,app(FiscalTaxRuleResolver::class)->conflicts($second));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Regras fiscais conflitantes');
        app(FiscalTaxRuleResolver::class)->resolve('nfe','BA','SP','1',[
            'operation_type'=>'outbound','purpose'=>'normal','recipient_profile'=>'contributor',
            'fiscal_tax_group_id'=>$group->id],'2026-10-26');
    }

    public function test_changed_product_group_or_group_review_requires_revalidation_even_for_the_same_wildcard_rule(): void
    {
        $this->install(); [$draft,$product,$group]=$this->draft(); $this->rule();
        $service=app(Nfe55TaxRuleApplicationService::class);
        $service->apply($draft,CompanySetting::current());
        self::assertSame($group->id,$draft->items->first()->tax_data['fiscal_rule_trace']['catalog_models']['group']['id']);
        $group->update(['revision'=>2,'notes'=>'Revisão do tratamento específico']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('catálogo fiscal mudou');
        $service->assertCurrent($draft,CompanySetting::current());
    }

    public function test_new_reference_snapshot_requires_explicit_draft_revalidation(): void
    {
        $this->install(); [$draft]=$this->draft(); $this->rule();
        $service=app(Nfe55TaxRuleApplicationService::class);
        $service->apply($draft,CompanySetting::current());
        $catalog=app(FiscalCatalogService::class);
        $release=$catalog->current();
        $catalog->import(['schema_version'=>1,'version'=>'determination-test-2','consulted_at'=>'2026-10-26',
            'sources'=>array_values($release->sources),'coverage'=>$release->coverage,
            'entries'=>$release->entries->map(fn($entry)=>[
                'kind'=>$entry->kind,'code'=>$entry->code,'description'=>$entry->description,'source_id'=>'fixture',
            ])->all()]);
        try {
            $service->assertCurrent($draft,CompanySetting::current());
            self::fail('A new reference snapshot needs an explicit validation of the mutable draft.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('catálogo fiscal mudou',$error->getMessage());
        }
        $service->apply($draft,CompanySetting::current());
        $service->assertCurrent($draft,CompanySetting::current());
        self::assertSame('determination-test-2',$draft->items->first()->tax_data['fiscal_rule_trace']['classification_catalog']['version']);
    }

    public function test_nullable_catalog_migration_preserves_legacy_reserved_rule_snapshot_hash(): void
    {
        $this->install();
        $rule=$this->rule(['catalog_version'=>null,'catalog_status'=>null,'fiscal_reviewed_at'=>null])->fresh();
        $trace=$rule->trace();
        foreach (['operation_nature_id','fiscal_tax_group_id','origin_municipality','destination_municipality',
            'recipient_icms_indicator','catalog_version','catalog_status','fiscal_reviewed_at','fiscal_reviewed_by'] as $field) {
            self::assertArrayNotHasKey($field,$trace['snapshot']);
        }
        self::assertSame($trace['hash'],$rule->fresh()->trace()['hash']);
    }

    public function test_extra_unreviewed_fcp_rate_is_blocked_in_catalog_automatic_mode(): void
    {
        $this->install(); [$draft]=$this->draft(); $this->rule();
        $draft->items->first()->tax_data=['fcp_rate'=>'2.0000'];
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fcp_rate não consta');
        app(Nfe55TaxRuleApplicationService::class)->apply($draft,CompanySetting::current(),false);
    }

    public function test_extra_rate_must_match_the_reviewed_group_while_transaction_amounts_can_vary(): void
    {
        $this->install(); [$draft,$product,$group]=$this->draft();
        $group->update(['tax_config'=>['fcp_st_retained_rate'=>'2.0000']]);
        $this->rule(['tax_parameters'=>['icms_st_retained_base'=>'100.00','icms_st_retained_value'=>'10.00']]);
        $draft->items->first()->tax_data=['fcp_st_retained_rate'=>'2.0000','icms_st_retained_base'=>'123.45','icms_st_retained_value'=>'12.34'];
        app(Nfe55TaxRuleApplicationService::class)->apply($draft,CompanySetting::current(),false);
        $tax=$draft->items->first()->tax_data;
        self::assertSame('2.0000',$tax['fcp_st_retained_rate']);
        self::assertSame('123.45',$tax['icms_st_retained_base']);
        self::assertSame('12.34',$tax['icms_st_retained_value']);
        self::assertSame('12.34',$tax['fiscal_rule_trace']['overrides']['icms_st_retained_value']['item']);
    }

    public function test_unreviewed_item_benefit_cannot_be_added_to_a_managed_rule(): void
    {
        $this->install(); [$draft]=$this->draft(); $this->rule();
        $draft->items->first()->fiscal_benefit_code='BA123456';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('benefício fiscal do item não consta');
        app(Nfe55TaxRuleApplicationService::class)->apply($draft,CompanySetting::current(),false);
    }

    public function test_reviewed_group_parameters_feed_real_totals_and_specific_rule_overrides_without_product_mutation(): void
    {
        $this->install(); [$draft,$product,$group]=$this->draft();
        $productBefore=$product->fresh()->getAttributes();
        $group->update(['ipi_cst'=>'50','tax_config'=>[
            'ipi_rate'=>'10.0000','ipi_enq'=>'999','iss_rate'=>'99.0000',
            'municipal_variations'=>[['city_ibge'=>'2927408','rate'=>'5.0000']],
        ]]);
        $parameters=['pis_rate'=>'0.0000','pis_calculation_type'=>'percent','cofins_rate'=>'0.0000','cofins_calculation_type'=>'percent'];
        $rule=$this->rule(['tax_parameters'=>$parameters]);
        $service=app(Nfe55TaxRuleApplicationService::class);
        $service->apply($draft,CompanySetting::current(),false);
        $tax=$draft->items->first()->tax_data;
        self::assertSame('50',$tax['ipi_cst']);
        self::assertSame('999',$tax['ipi_enq']);
        self::assertSame('10.0000',$tax['ipi_rate']);
        self::assertArrayNotHasKey('iss_rate',$tax);
        self::assertArrayNotHasKey('municipal_variations',$tax);
        $totals=app(\App\Services\Fiscal\Nfe55DraftTotalsService::class)->calculate(
            $draft->items->map(fn($item)=>$item->toArray())->all(),
            ['crt'=>'1','operation_type'=>'outbound','purpose'=>'normal','issue_date'=>'2026-10-26']);
        self::assertTrue($totals['tax_calculation_complete'],implode(' ', $totals['tax_errors']));
        self::assertSame(10.0,$totals['ipi']);
        self::assertSame(110.0,$totals['total']);
        self::assertSame($productBefore,$product->fresh()->getAttributes());
        $rule->update(['tax_parameters'=>[...$parameters,'ipi_rate'=>'7.0000']]);
        $service->apply($draft,CompanySetting::current(),false);
        self::assertSame('7.0000',$draft->items->first()->tax_data['ipi_rate']);
        $item=$draft->items->first(); $item->tax_data=[...$item->tax_data,'ipi_rate'=>'12.0000'];
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ipi_rate difere da regra');
        $service->apply($draft,CompanySetting::current(),false);
    }

}
