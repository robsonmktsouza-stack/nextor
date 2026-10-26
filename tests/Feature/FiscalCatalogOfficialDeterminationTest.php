<?php

namespace Tests\Feature;

use App\Models\{CompanySetting,FiscalTaxRule,NfeDraft,OperationNature,User};
use App\Services\Fiscal\{FiscalCatalogService,Nfe55IniBuilder,Nfe55TaxRuleApplicationService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\WithConfiguredCompany;
use Tests\Concerns\WithIsolatedFiscalCatalog;
use Tests\TestCase;

final class FiscalCatalogOfficialDeterminationTest extends TestCase
{
    use RefreshDatabase, WithIsolatedFiscalCatalog;
    use WithConfiguredCompany { setUp as private setUpConfiguredCompany; }

    protected function setUp(): void
    {
        $this->setUpConfiguredCompany();
        $this->isolateFiscalCatalog();
    }

    private function manifest(array $benefits = [], array $cfops = [], string $version = 'official-flow-test-1'): array
    {
        $this->travelTo(Carbon::parse('2026-10-26 12:00:00'));
        return ['schema_version'=>1,'version'=>$version,'consulted_at'=>'2026-10-26',
            'sources'=>[['id'=>'fixture','url'=>'https://www.confaz.fazenda.gov.br/legislacao',
                'reference'=>'Fixture sintética de validação; não é distribuição de benefícios ou enquadramentos fiscais.',
                'consulted_at'=>'2026-10-26']],
            'coverage'=>['cfop'=>'partial','ncm'=>'partial','benefit'=>$benefits ? 'partial' : 'not_bundled'],
            'entries'=>[
                ...array_map(fn ($entry)=>array_replace(['kind'=>'cfop','description'=>'CFOP de teste','source_id'=>'fixture'], $entry), $cfops ?: [
                    ['code'=>'5102','parameters'=>['ind_nfe'=>true,'indNFe'=>1]],
                    ['code'=>'5902','parameters'=>['ind_nfe'=>true]],
                ]),
                ['kind'=>'ncm','code'=>'61091000','description'=>'Classificação de teste','source_id'=>'fixture'],
                ...array_map(fn ($entry)=>array_replace(['kind'=>'benefit','code'=>'BA123456','description'=>'Benefício fictício de teste',
                    'uf'=>'BA','valid_from'=>'2026-01-01','valid_until'=>'2026-12-31','source_id'=>'fixture',
                    'parameters'=>['icms_csts'=>['20'],'crts'=>['3']]], $entry), $benefits),
            ]];
    }

    private function install(array $benefits = [], array $cfops = []): void
    {
        app(FiscalCatalogService::class)->import($this->manifest($benefits, $cfops));
    }

    private function reviewedRule(array $extra = []): array
    {
        return array_replace([
            'name'=>'Regra de teste revisada','document_type'=>'nfe','origin_uf'=>'BA','destination_uf'=>'BA',
            'crt'=>'3','csosn'=>'','icms_cst'=>'20','cfop'=>'5102','pis_cst'=>'49','cofins_cst'=>'49',
            'operation_type'=>'outbound','purpose'=>'normal','recipient_profile'=>'contributor','priority'=>1,
            'valid_from'=>'2026-01-01','valid_until'=>'2026-12-31','is_active'=>true,
            'legal_source'=>'https://www.confaz.fazenda.gov.br/legislacao','legal_reference'=>'Fixture sem enquadramento de produção',
            'layout_version'=>'Teste','consulted_at'=>'2026-10-26','catalog_version'=>'official-flow-test-1',
            'catalog_status'=>'reviewed','fiscal_reviewed_at'=>now(),
        ], $extra);
    }

    private function draft(bool $managed = true): array
    {
        $company=CompanySetting::current();
        $company->update(['crt'=>'3','tax_regime'=>'lucro_real','city_ibge_code'=>'2927408']);
        $nature=OperationNature::query()->create([
            'name'=>'Operação de teste','operation_type'=>'outbound','purpose'=>'normal','is_active'=>true,
            'catalog_version'=>$managed ? 'official-flow-test-1' : null,
            'catalog_status'=>$managed ? 'reviewed' : null,'fiscal_reviewed_at'=>$managed ? now() : null,
        ]);
        $draft=NfeDraft::query()->create(['operation_nature_id'=>$nature->id,'status'=>'draft','operation_type'=>'outbound',
            'destination'=>'internal','purpose'=>'normal','issue_date'=>'2026-10-26','issue_time'=>'12:00','final_consumer'=>false,
            'recipient_snapshot'=>['state'=>'BA','city_ibge_code'=>'2927408','ie_indicator'=>'contributor','state_registration'=>'12345678']]);
        $draft->items()->create(['item_number'=>1,'product_name'=>'Mercadoria de teste','quantity'=>'1','unit_price'=>'100',
            'line_total'=>'100','ncm'=>'61091000','origin'=>'0','tax_data'=>[]]);
        return [$draft->load('items'),$company];
    }

    public function test_managed_benefit_uses_exact_state_period_crt_and_cst_and_records_source_evidence(): void
    {
        $this->install([[]]); [$draft,$company]=$this->draft();
        FiscalTaxRule::query()->create($this->reviewedRule(['fiscal_benefit_code'=>'BA123456']));
        $service=app(Nfe55TaxRuleApplicationService::class);
        $service->apply($draft,$company);
        $trace=$draft->items->first()->tax_data['fiscal_rule_trace']['benefit_reference'];
        self::assertSame('BA123456',$draft->items->first()->fiscal_benefit_code);
        self::assertSame('BA',$trace['uf']);
        self::assertSame('2026-01-01',$trace['valid_from']);
        self::assertSame('official-flow-test-1',$trace['release']);
        self::assertSame(['20'],$trace['parameters']['icms_csts']);
        self::assertSame('20',$trace['verified_context']['classification']);
        self::assertSame('https://www.confaz.fazenda.gov.br/legislacao',$trace['source']['url']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/',$trace['release_sha256']);
        $service->assertCurrent($draft,$company);
    }

    public static function incompatibleBenefits(): array
    {
        return [
            'wrong state'=>[['uf'=>'SP'],'3','20',null],
            'expired'=>[['valid_until'=>'2026-10-25'],'3','20',null],
            'not yet in force'=>[['valid_from'=>'2026-10-27'],'3','20',null],
            'unproven start date'=>[['valid_from'=>null],'3','20',null],
            'wrong CST'=>[['parameters'=>['icms_csts'=>['40']]],'3','20',null],
            'wrong CRT'=>[['parameters'=>['icms_csts'=>['20'],'crts'=>['2']]],'3','20',null],
            'reference only'=>[['parameters'=>['icms_csts'=>['20'],'automatic_application'=>false]],'3','20',null],
            'unverified mapping'=>[['parameters'=>['icms_csts'=>['20'],'cst_mapping_verified'=>false]],'3','20',null],
            'CST is not CSOSN'=>[['parameters'=>['icms_csts'=>['40'],'applies_to_simples'=>true]],'1',null,'400'],
        ];
    }

    #[DataProvider('incompatibleBenefits')]
    public function test_incomplete_or_incompatible_official_benefit_never_becomes_an_automatic_rule(array $entry,string $crt,?string $cst,?string $csosn): void
    {
        $this->install([$entry]);
        $this->expectException(RuntimeException::class);
        app(Nfe55TaxRuleApplicationService::class)->assertCatalogBenefit('BA123456','BA',$crt,$cst,$csosn,'2026-10-26');
    }

    public function test_absent_state_benefit_table_blocks_catalog_automatic_application(): void
    {
        $this->install(); [$draft,$company]=$this->draft();
        FiscalTaxRule::query()->create($this->reviewedRule(['fiscal_benefit_code'=>'BA123456']));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sem referência oficial vigente para BA');
        app(Nfe55TaxRuleApplicationService::class)->apply($draft,$company,false);
    }

    public function test_an_explicit_official_csosn_mapping_is_required_for_simples(): void
    {
        $this->install([['parameters'=>['csosns'=>['400'],'crts'=>['1'],'applies_to_simples'=>true]]]);
        $trace=app(Nfe55TaxRuleApplicationService::class)->assertCatalogBenefit('BA123456','BA','1',null,'400','2026-10-26');
        self::assertSame('csosns',$trace['verified_context']['classification_type']);
        self::assertSame('400',$trace['verified_context']['classification']);
    }

    public function test_new_snapshot_revocation_blocks_a_previously_determined_benefit(): void
    {
        $this->install([[]]); [$draft,$company]=$this->draft();
        FiscalTaxRule::query()->create($this->reviewedRule(['fiscal_benefit_code'=>'BA123456']));
        $service=app(Nfe55TaxRuleApplicationService::class); $service->apply($draft,$company);
        app(FiscalCatalogService::class)->import($this->manifest([['status'=>'revoked']],version:'official-flow-test-2'));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sem referência oficial vigente');
        $service->assertCurrent($draft,$company);
    }

    public function test_cfop_official_nfe_flag_is_respected_without_fabricating_absent_flags(): void
    {
        $this->install([], [
            ['code'=>'5102','parameters'=>['ind_nfe'=>true,'indNFe'=>1]],
            ['code'=>'5405','parameters'=>[]],
            ['code'=>'5501','parameters'=>['ind_nfe'=>false,'indNFe'=>0]],
        ]);
        $service=app(Nfe55TaxRuleApplicationService::class);
        self::assertTrue($service->assertCatalogCfop('5102','BA','2026-10-26')['document_eligibility']['ind_nfe']);
        self::assertArrayNotHasKey('document_eligibility',$service->assertCatalogCfop('5405','BA','2026-10-26'));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('não permite NF-e modelo 55');
        $service->assertCatalogCfop('5501','BA','2026-10-26');
    }

    public function test_official_cfop_presence_does_not_bypass_supported_operation_policy(): void
    {
        $this->install(); [$draft,$company]=$this->draft();
        $company->update(['crt'=>'1','tax_regime'=>'simples_nacional']);
        FiscalTaxRule::query()->create($this->reviewedRule(['crt'=>'1','icms_cst'=>null,'csosn'=>'102','cfop'=>'5902']));
        app(Nfe55TaxRuleApplicationService::class)->apply($draft,$company);
        self::assertSame('5902',$draft->items->first()->cfop);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exige uma operação fiscal específica');
        app(Nfe55IniBuilder::class)->build($draft,$company,1,12345678);
    }

    public function test_manually_reviewed_legacy_rule_is_preserved_without_claiming_catalog_benefit_approval(): void
    {
        $this->install(); [$draft,$company]=$this->draft(false);
        FiscalTaxRule::query()->create($this->reviewedRule(['catalog_version'=>null,'catalog_status'=>null,
            'fiscal_reviewed_at'=>null,'fiscal_benefit_code'=>'BA123456']));
        app(Nfe55TaxRuleApplicationService::class)->apply($draft,$company);
        self::assertSame('BA123456',$draft->items->first()->fiscal_benefit_code);
        self::assertArrayNotHasKey('benefit_reference',$draft->items->first()->tax_data['fiscal_rule_trace']);
    }

    public function test_admin_cannot_activate_managed_benefit_without_official_state_mapping(): void
    {
        $this->install();
        $admin=User::query()->create(['name'=>'Revisor','email'=>'benefit-review@example.test','password'=>'password','role'=>'admin','is_active'=>true]);
        $payload=$this->reviewedRule(['fiscal_benefit_code'=>'BA123456','fiscal_review_confirmed'=>true]);
        unset($payload['fiscal_reviewed_at']);
        $this->actingAs($admin)->post(route('fiscal.tax-rules.store'),$payload)->assertSessionHasErrors('fiscal_benefit_code');
        self::assertSame(0,FiscalTaxRule::query()->count());
    }
    public function test_admin_activation_records_a_benefit_only_with_compatible_official_state_evidence(): void
    {
        $this->install([[]]);
        $admin=User::query()->create(['name'=>'Revisor','email'=>'benefit-approved@example.test','password'=>'password','role'=>'admin','is_active'=>true]);
        $payload=$this->reviewedRule(['fiscal_benefit_code'=>'BA123456','fiscal_review_confirmed'=>true]);
        unset($payload['fiscal_reviewed_at']);
        $this->actingAs($admin)->post(route('fiscal.tax-rules.store'),$payload)->assertSessionHasNoErrors();
        self::assertSame('BA123456',FiscalTaxRule::query()->firstOrFail()->fiscal_benefit_code);
        self::assertSame($admin->id,FiscalTaxRule::query()->firstOrFail()->fiscal_reviewed_by);
    }

    public function test_admin_cannot_activate_a_cfop_with_explicit_official_restriction_for_nfe(): void
    {
        $this->install([], [['code'=>'5501','parameters'=>['ind_nfe'=>false,'indNFe'=>0]]]);
        $admin=User::query()->create(['name'=>'Revisor','email'=>'cfop-restriction@example.test','password'=>'password','role'=>'admin','is_active'=>true]);
        $payload=$this->reviewedRule(['cfop'=>'5501','fiscal_review_confirmed'=>true]);
        unset($payload['fiscal_reviewed_at']);
        $this->actingAs($admin)->post(route('fiscal.tax-rules.store'),$payload)->assertSessionHasErrors('cfop');
        self::assertSame(0,FiscalTaxRule::query()->count());
    }

}
