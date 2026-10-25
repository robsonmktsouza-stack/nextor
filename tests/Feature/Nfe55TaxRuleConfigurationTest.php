<?php

namespace Tests\Feature;

use App\Models\CompanySetting;
use App\Models\FiscalTaxRule;
use App\Models\NfeDraft;
use App\Models\NfeDraftItem;
use App\Models\User;
use App\Services\Fiscal\FiscalTaxRuleResolver;
use App\Services\Fiscal\Nfe55TaxRuleApplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\WithConfiguredCompany;
use Tests\TestCase;

final class Nfe55TaxRuleConfigurationTest extends TestCase
{
    use RefreshDatabase, WithConfiguredCompany;

    private function data(array $extra = []): array
    {
        return array_replace([
            'name'=>'Regra NF-e aprovada','document_type'=>'nfe','origin_uf'=>'BA','destination_uf'=>'SP',
            'crt'=>'3','icms_cst'=>'00','csosn'=>'','cfop'=>'6102','pis_cst'=>'01','cofins_cst'=>'01',
            'operation_type'=>'outbound','purpose'=>'normal','recipient_profile'=>'contributor',
            'valid_from'=>'2026-01-01','valid_until'=>'2026-12-31','priority'=>0,'is_active'=>true,
            'legal_source'=>'https://www.confaz.fazenda.gov.br/legislacao',
            'legal_reference'=>'Referência de teste; não constitui enquadramento fiscal',
            'layout_version'=>'NF-e 4.00 fixture','consulted_at'=>'2026-10-01',
            'tax_parameters'=>['icms_rate'=>'12.0000','ipi_cst'=>'53','ipi_enq'=>'999'],
        ], $extra);
    }

    private function administrator(): User
    {
        return User::query()->create(['name'=>'Admin regras','email'=>'rules@example.test',
            'password'=>'password','role'=>'admin','is_active'=>true]);
    }

    private function resolve(array $extra = [], string $date = '2026-10-25'): ?FiscalTaxRule
    {
        return app(FiscalTaxRuleResolver::class)->resolve('nfe','BA','SP','3',array_replace([
            'ncm'=>'61091000','cfop'=>'6102','cest'=>'2805900','origin'=>'0',
            'operation_type'=>'outbound','purpose'=>'normal','recipient_profile'=>'contributor',
            'final_consumer'=>false,
        ], $extra), $date);
    }

    public function test_configuration_creates_and_retains_both_revisions_and_rejects_stale_editor(): void
    {
        $admin = $this->administrator();
        $this->actingAs($admin)->post(route('fiscal.tax-rules.store'), $this->data())->assertSessionHasNoErrors();
        $rule = FiscalTaxRule::query()->firstOrFail();
        self::assertSame(1, $rule->revision);
        self::assertSame(1, $rule->revisions()->count());
        $oldHash = $rule->revisions()->first()->snapshot_hash;
        $this->put(route('fiscal.tax-rules.update',$rule),$this->data([
            'revision'=>1,'tax_parameters'=>['icms_rate'=>'7.0000'],
        ]))->assertSessionHasNoErrors();
        $rule->refresh();
        self::assertSame(2,$rule->revision);
        self::assertSame(2,$rule->revisions()->count());
        self::assertSame($oldHash,$rule->revisions()->where('revision',1)->first()->snapshot_hash);
        self::assertSame('12.0000',$rule->revisions()->where('revision',1)->first()->snapshot['tax_parameters']['icms_rate']);
        $this->put(route('fiscal.tax-rules.update',$rule),$this->data(['revision'=>1]))->assertSessionHasErrors('revision');
        self::assertSame(2,$rule->fresh()->revision);
    }

    public function test_active_equal_rank_overlap_is_rejected_in_form_and_at_resolution(): void
    {
        $this->actingAs($this->administrator());
        $this->post(route('fiscal.tax-rules.store'),$this->data())->assertSessionHasNoErrors();
        $this->post(route('fiscal.tax-rules.store'),$this->data(['name'=>'Conflito']))->assertSessionHasErrors('priority');
        self::assertSame(1,FiscalTaxRule::query()->count());
        FiscalTaxRule::query()->create($this->data(['name'=>'Carga externa ambígua']));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Regras fiscais conflitantes');
        $this->resolve();
    }

    public function test_non_overlapping_periods_are_allowed_and_boundaries_are_inclusive(): void
    {
        $a = FiscalTaxRule::query()->create($this->data(['valid_until'=>'2026-10-25']));
        $b = FiscalTaxRule::query()->create($this->data(['name'=>'Após corte','valid_from'=>'2026-10-26']));
        self::assertSame($a->id,$this->resolve([], '2026-10-25')->id);
        self::assertSame($b->id,$this->resolve([], '2026-10-26')->id);
        self::assertNull($this->resolve([], '2027-01-01'));
    }

    public function test_context_criteria_match_exactly_and_priority_precedes_specificity_for_nfe(): void
    {
        $general=FiscalTaxRule::query()->create($this->data(['priority'=>10]));
        $specific=FiscalTaxRule::query()->create($this->data(['name'=>'Específica','priority'=>5,
            'ncm_prefix'=>'6109','cest'=>'2805900','origin'=>'0','input_cfop'=>'6102','final_consumer'=>false]));
        self::assertSame($general->id,$this->resolve()->id);
        $general->update(['is_active'=>false]);
        self::assertSame($specific->id,$this->resolve()->id);
        self::assertNull($this->resolve(['cest'=>'0000000']));
        self::assertNull($this->resolve(['final_consumer'=>true]));
        self::assertNull($this->resolve(['recipient_profile'=>'non_contributor']));
    }

    public function test_normative_metadata_and_cfop_geography_are_validated_without_default_rates(): void
    {
        $this->actingAs($this->administrator());
        $this->post(route('fiscal.tax-rules.store'),$this->data(['legal_source'=>'']))->assertSessionHasErrors('legal_source');
        $this->post(route('fiscal.tax-rules.store'),$this->data(['cfop'=>'5102']))->assertSessionHasErrors('cfop');
        $this->post(route('fiscal.tax-rules.store'),$this->data(['tax_parameters'=>['icms_rate'=>'100.0001']]))->assertSessionHasErrors('tax_parameters_json');
        $this->post(route('fiscal.tax-rules.store'),$this->data(['tax_parameters'=>['invented'=>'18']]))->assertSessionHasErrors('tax_parameters_json');
        self::assertSame(0,FiscalTaxRule::query()->count());
    }

    public function test_preview_applies_only_missing_defaults_and_preserves_explicit_overrides_with_trace(): void
    {
        $rule=FiscalTaxRule::query()->create($this->data());
        $company=CompanySetting::current();$company->state='BA';$company->crt='3';
        $draft=new NfeDraft(['status'=>'draft','operation_type'=>'outbound','purpose'=>'normal',
            'issue_date'=>'2026-10-25','recipient_snapshot'=>['state'=>'SP','ie_indicator'=>'contributor']]);
        $item=new NfeDraftItem(['cfop'=>'6102','ncm'=>'61091000','origin'=>'0','tax_data'=>['icms_rate'=>'7.0000']]);
        $draft->setRelation('items',collect([$item]));
        app(Nfe55TaxRuleApplicationService::class)->apply($draft,$company,false);
        self::assertFalse($item->exists);
        self::assertSame('7.0000',$item->tax_data['icms_rate']);
        self::assertSame('00',$item->tax_data['icms_cst']);
        self::assertSame($rule->id,$item->tax_data['fiscal_rule_trace']['id']);
        self::assertSame('12.0000',$item->tax_data['fiscal_rule_trace']['overrides']['icms_rate']['rule']);
        self::assertSame($rule->legal_source,$item->tax_data['fiscal_rule_trace']['legal_source']);
        $rule->update(['tax_parameters'=>['icms_rate'=>'18.0000']]);
        self::assertSame('12.0000',$item->tax_data['fiscal_rule_trace']['snapshot']['tax_parameters']['icms_rate']);
    }

    public function test_rule_application_refuses_reserved_document_and_does_not_mutate_item(): void
    {
        $draft=NfeDraft::query()->create(['status'=>'queued','issue_date'=>'2026-10-25','document_number'=>4]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('antes da reserva');
        app(Nfe55TaxRuleApplicationService::class)->apply($draft);
    }

    public function test_rule_refresh_updates_only_inherited_values_and_keeps_manual_overrides(): void
    {
        $rule=FiscalTaxRule::query()->create($this->data(['tax_parameters'=>['icms_rate'=>'10.0000','pis_rate'=>'1.0000']]));
        $company=CompanySetting::current();$company->state='BA';$company->crt='3';
        $draft=new NfeDraft(['status'=>'draft','operation_type'=>'outbound','purpose'=>'normal',
            'issue_date'=>'2026-10-25','recipient_snapshot'=>['state'=>'SP','ie_indicator'=>'contributor']]);
        $inherited=new NfeDraftItem(['cfop'=>'6102','ncm'=>'61091000','origin'=>'0','tax_data'=>[]]);
        $manual=new NfeDraftItem(['cfop'=>'6102','ncm'=>'61091000','origin'=>'0','tax_data'=>['icms_rate'=>'20.0000']]);
        $draft->setRelation('items',collect([$inherited,$manual]));
        $service=app(Nfe55TaxRuleApplicationService::class);
        $service->apply($draft,$company,false);
        self::assertSame('10.0000',$inherited->tax_data['icms_rate']);
        $service->assertCurrent($draft,$company);
        $rule->update(['tax_parameters'=>['icms_rate'=>'12.0000','pis_rate'=>'2.0000'],'revision'=>2]);
        try {
            $service->assertCurrent($draft,$company);
            self::fail('A changed source revision must block emission until revalidation.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('Revalide', $error->getMessage());
        }
        $service->apply($draft,$company,false);
        $service->assertCurrent($draft,$company);
        self::assertSame('12.0000',$inherited->tax_data['icms_rate']);
        self::assertSame('20.0000',$manual->tax_data['icms_rate']);
        self::assertSame('2.0000',$manual->tax_data['pis_rate']);
        self::assertSame(2,$inherited->tax_data['fiscal_rule_trace']['revision']);
        self::assertSame('12.0000',$manual->tax_data['fiscal_rule_trace']['overrides']['icms_rate']['rule']);
        // When coverage is removed, inherited amounts cannot survive disguised as manual values.
        $rule->update(['is_active'=>false]);
        $service->apply($draft,$company,false);
        self::assertArrayNotHasKey('icms_rate',$inherited->tax_data);
        self::assertSame('20.0000',$manual->tax_data['icms_rate']);
        self::assertArrayNotHasKey('fiscal_rule_trace',$manual->tax_data);
    }

    public function test_inbound_overrides_are_traced_using_the_actual_calculator_parameter(): void
    {
        FiscalTaxRule::query()->create($this->data(['operation_type'=>'inbound','cfop'=>'2102']));
        $company=CompanySetting::current();$company->state='BA';$company->crt='3';
        $draft=new NfeDraft(['status'=>'draft','operation_type'=>'inbound','purpose'=>'normal',
            'issue_date'=>'2026-10-25','recipient_snapshot'=>['state'=>'SP','ie_indicator'=>'contributor']]);
        $item=new NfeDraftItem(['cfop'=>'2102','ncm'=>'61091000','origin'=>'0',
            'tax_data'=>['icms_cst'=>'00','icms_cst_inbound'=>'40']]);
        $draft->setRelation('items',collect([$item]));
        app(Nfe55TaxRuleApplicationService::class)->apply($draft,$company,false);
        self::assertSame('40',$item->tax_data['icms_cst']);
        self::assertSame('00',$item->tax_data['fiscal_rule_trace']['overrides']['icms_cst']['rule']);
        self::assertSame('40',$item->tax_data['fiscal_rule_trace']['overrides']['icms_cst']['item']);
    }
}
