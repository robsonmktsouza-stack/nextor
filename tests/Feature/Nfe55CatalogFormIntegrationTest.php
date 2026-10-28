<?php

namespace Tests\Feature;

use App\Models\{CompanySetting,Customer,FiscalTaxGroup,FiscalTaxRule,NfeDraft,NfeDraftItem,OperationNature,Product,User};
use App\Services\Fiscal\{FiscalCatalogService,Nfe55TaxRuleApplicationService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithConfiguredCompany;
use Tests\Concerns\WithIsolatedFiscalCatalog;
use Tests\TestCase;

final class Nfe55CatalogFormIntegrationTest extends TestCase
{
    use RefreshDatabase, WithIsolatedFiscalCatalog;
    use WithConfiguredCompany { setUp as private setUpConfiguredCompany; }

    protected function setUp(): void
    {
        $this->setUpConfiguredCompany();
        $this->isolateFiscalCatalog();
    }

    private function fixture(): array
    {
        $this->actingAs(User::query()->create(['name'=>'Fiscal teste','email'=>'catalog-form@example.test',
            'password'=>'password','role'=>'admin','is_active'=>true]));
        $company=CompanySetting::current();
        $company->update(['crt'=>'1','state'=>'BA','tax_regime'=>'simples_nacional','city_ibge_code'=>'2927408']);
        $today=now()->toDateString();
        // Minimal synthetic snapshot exercises integration; this is not a distributed legal rule.
        app(FiscalCatalogService::class)->import(['schema_version'=>1,'version'=>'form-fixture-1',
            'consulted_at'=>now('UTC')->toDateString(),'sources'=>[['id'=>'fixture',
                'url'=>'https://portalunico.siscomex.gov.br/classif/',
                'reference'=>'Fixture de integração, sem classificação automática',
                'consulted_at'=>now('UTC')->toDateString()]],'coverage'=>['ncm'=>'partial','cfop'=>'partial'],
            'entries'=>[['kind'=>'ncm','code'=>'61091000','description'=>'Classificação de teste',
                'source_id'=>'fixture','valid_from'=>'2026-01-01'],
                ['kind'=>'cfop','code'=>'5102','description'=>'CFOP de teste',
                'source_id'=>'fixture','valid_from'=>'2026-01-01']]]);
        $review=['catalog_version'=>'form-fixture-1','catalog_status'=>'reviewed','fiscal_reviewed_at'=>now()];
        $nature=OperationNature::query()->create($review+['name'=>'Revenda revisada de teste','operation_type'=>'outbound',
            'purpose'=>'normal','cfop_internal'=>'5102','is_active'=>true,
            'catalog_metadata'=>['required_context'=>['operation_type'=>['outbound']]]]);
        $group=FiscalTaxGroup::query()->create($review+['name'=>'Grupo revisado de teste','kind'=>'products',
            'is_active'=>true,'target_crt'=>'1','tax_config'=>[]]);
        $product=Product::query()->create(['name'=>'Mercadoria de teste','sku'=>'CAT-FORM-1','ncm'=>'61091000',
            'unit'=>'UN','origin'=>'0','sale_price'=>'100','usage_type'=>'resale','is_active'=>true,
            'fiscal_tax_group_id'=>$group->id,'tax_defaults'=>[]]);
        $customer=Customer::query()->create(['name'=>'Destinatário de teste','is_customer'=>true,
            'document'=>'11144477735','state'=>'BA','city'=>'Salvador','city_ibge_code'=>'2927408',
            'ie_indicator'=>'non_contributor','final_consumer'=>true]);
        $rule=FiscalTaxRule::query()->create($review+['name'=>'Regra revisada de teste','document_type'=>'nfe',
            'origin_uf'=>'BA','destination_uf'=>'BA','crt'=>'1','operation_nature_id'=>$nature->id,
            'fiscal_tax_group_id'=>$group->id,'cfop'=>'5102','csosn'=>'102','pis_cst'=>'49','cofins_cst'=>'49',
            'tax_parameters'=>['pis_rate'=>'0','cofins_rate'=>'0'],'priority'=>1,'revision'=>1,
            'is_active'=>true,'valid_from'=>'2026-01-01','legal_source'=>'https://www.nfe.fazenda.gov.br/',
            'legal_reference'=>'Fixture explícita, não distribuída como regra fiscal',
            'layout_version'=>'Teste','consulted_at'=>$today]);
        $items=[['product_id'=>$product->id,'product_name'=>$product->name,'quantity'=>'1',
            'dimension_quantity'=>'1','unit_price'=>'100','unit'=>'UN','origin'=>'0','ncm'=>'61091000',
            'cfop'=>'','tax_data'=>[]]];
        $payload=['draft_action'=>'draft','operation_nature_id'=>$nature->id,'customer_id'=>$customer->id,
            'operation_type'=>'outbound','destination'=>'internal','purpose'=>'normal','fiscal_operation'=>'sale',
            'final_consumer'=>'1','presence'=>'not_applicable','issue_date'=>$today,'issue_time'=>'12:00',
            'freight_mode'=>'9','payment_type'=>'01','payment_condition'=>'a_vista',
            'items_json'=>json_encode($items,JSON_THROW_ON_ERROR),'duplicates_json'=>'[]',
            'payments_json'=>'[]','references_json'=>'[]'];
        return compact('company','nature','group','product','customer','rule','payload');
    }

    public function test_live_preview_uses_selected_nature_group_and_rule_without_writes(): void
    {
        $f=$this->fixture();
        $before=$f['product']->fresh()->getAttributes();
        $this->postJson(route('fiscal.nfe.preview-taxes'),$f['payload'])->assertOk()
            ->assertJsonPath('applied_rules.0.id',$f['rule']->id)
            ->assertJsonPath('applied_rules.0.cfop','5102')
            ->assertJsonPath('applied_rules.0.csosn','102')
            ->assertJsonPath('applied_rules.0.catalog_version','form-fixture-1');
        self::assertSame(0,NfeDraft::query()->count());
        self::assertSame(0,NfeDraftItem::query()->count());
        self::assertSame($before,$f['product']->fresh()->getAttributes());
    }

    public function test_preview_rejects_unreviewed_template_instead_of_ignoring_selected_nature(): void
    {
        $f=$this->fixture();
        $f['nature']->update(['catalog_status'=>'template','is_active'=>false,'fiscal_reviewed_at'=>null]);
        $this->postJson(route('fiscal.nfe.preview-taxes'),$f['payload'])->assertUnprocessable()
            ->assertJsonValidationErrors('items_json');
        self::assertSame(0,NfeDraft::query()->count());
    }

    public function test_draft_retains_incomplete_catalog_data_but_cannot_pass_determination(): void
    {
        $f=$this->fixture();
        $f['rule']->update(['is_active'=>false]);
        $this->post(route('fiscal.nfe.store'),$f['payload'])->assertRedirect()->assertSessionHasNoErrors();
        $draft=NfeDraft::query()->firstOrFail();
        self::assertSame('draft',$draft->status);
        self::assertNull($draft->document_number);
        self::assertNull($draft->access_key);
        self::assertSame($f['nature']->id,$draft->operation_nature_id);
        self::assertNotEmpty(data_get($draft->custom_fields,'_editor_form.items_json'));
        $item=$draft->items()->firstOrFail();
        self::assertStringContainsString('nenhuma regra',data_get($item->tax_data,'fiscal_determination_error'));
        try {
            app(Nfe55TaxRuleApplicationService::class)->assertCurrent($draft,$f['company']);
            self::fail('Rascunho sem regra revisada foi aceito.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('nenhuma regra',$e->getMessage());
        }
        self::assertNull($draft->fresh()->document_number);
        self::assertSame([],$f['product']->fresh()->tax_defaults);
    }
}
