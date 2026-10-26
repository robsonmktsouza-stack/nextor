<?php

namespace Tests\Feature;

use App\Models\FiscalTaxGroup;
use App\Models\Product;
use App\Models\User;
use App\Services\Fiscal\FiscalCatalogService;
use App\Services\Fiscal\FiscalProductClassificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Concerns\WithConfiguredCompany;
use Tests\TestCase;

final class FiscalProductClassificationTest extends TestCase
{
    use RefreshDatabase;
    use WithConfiguredCompany { setUp as private setUpConfiguredCompany; }

    protected function setUp(): void
    {
        $this->setUpConfiguredCompany();
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-26 12:00:00'));
    }

    private function admin(): User
    {
        return User::query()->create(['name'=>'Catálogo de produtos','email'=>'product-catalog@example.test',
            'password'=>'test-password','role'=>'admin','is_active'=>true]);
    }

    private function manifest(string $coverage = 'complete'): array
    {
        return ['schema_version'=>1,'version'=>'classification-test-1','consulted_at'=>'2026-10-26',
            'sources'=>[['id'=>'test-reference','url'=>'https://portalunico.siscomex.gov.br/classif/',
                'reference'=>'Fixture isolada de classificação; não é distribuição oficial de catálogo.',
                'consulted_at'=>'2026-10-26']],
            'coverage'=>['ncm'=>$coverage,'cest'=>$coverage],
            'entries'=>[
                ['kind'=>'ncm','code'=>'61091000','description'=>'Camisetas de algodão','source_id'=>'test-reference'],
                ['kind'=>'ncm','code'=>'22030000','description'=>'Cervejas de malte','source_id'=>'test-reference'],
                ['kind'=>'cest','code'=>'0302100','description'=>'Cervejas','source_id'=>'test-reference',
                    'parameters'=>['ncm_prefixes'=>['2203']]],
            ]];
    }

    private function install(string $coverage = 'complete'): void
    {
        app(FiscalCatalogService::class)->import($this->manifest($coverage));
    }

    private function payload(array $changes = []): array
    {
        return array_replace(['name'=>'Produto classificado','usage_type'=>'resale','unit'=>'UN',
            'cost_price'=>'10.00','sale_price'=>'20.00','minimum_stock'=>'0.000','origin'=>'0',
            'ignore_taxes_mode'=>'none','control_stock'=>true,'is_active'=>true,'ncm'=>'6109.10.00'], $changes);
    }

    public function test_product_creation_normalizes_official_codes_and_preserves_explicit_exceptions(): void
    {
        $this->install();
        $this->actingAs($this->admin())->post(route('products.store'),$this->payload([
            'tax_defaults'=>['cfop_outbound_internal'=>'5102','icms_csosn'=>'102','pis_cst'=>'49'],
        ]))->assertRedirect(route('products.index'));
        $product=Product::query()->firstOrFail();
        self::assertSame('61091000',$product->ncm);
        self::assertSame('5102',$product->tax_defaults['cfop_outbound_internal']);
        self::assertSame('102',$product->tax_defaults['icms_csosn']);
        self::assertNull($product->cest);
        self::assertNull($product->fiscal_tax_group_id);
    }

    public function test_full_catalog_rejects_unknown_and_non_leaf_ncm_without_creating_products(): void
    {
        $this->install();
        $this->actingAs($this->admin());
        foreach (['99999999','6109','A61091000'] as $ncm) {
            $this->postJson(route('products.store'),$this->payload(['ncm'=>$ncm]))
                ->assertUnprocessable()->assertJsonValidationErrors('ncm');
        }
        $this->assertDatabaseCount('products',0);
    }

    public function test_official_cest_correspondence_checks_prefix_without_assigning_st(): void
    {
        $this->install();
        $this->actingAs($this->admin())->postJson(route('products.store'),$this->payload(['cest'=>'03.021.00']))
            ->assertUnprocessable()->assertJsonValidationErrors('cest');
        $this->post(route('products.store'),$this->payload(['ncm'=>'22030000','cest'=>'03.021.00']))
            ->assertRedirect(route('products.index'));
        $product=Product::query()->firstOrFail();
        self::assertSame('0302100',$product->cest);
        self::assertArrayNotHasKey('icms_st_rate',$product->tax_defaults);
        self::assertArrayNotHasKey('mva_rate',$product->tax_defaults);
        $trace=app(FiscalProductClassificationService::class)->trace($product->only(['ncm','cest']));
        self::assertSame('classification-test-1',$trace['version']);
        self::assertSame('0302100',$trace['entries']['cest']['reference']['code']);
        self::assertSame('https://portalunico.siscomex.gov.br/classif/',
            $trace['entries']['ncm']['reference']['source']['url']);
    }

    public function test_partial_catalog_does_not_invent_invalidity_of_a_missing_code(): void
    {
        $this->install('partial');
        $result=app(FiscalProductClassificationService::class)->validate(['ncm'=>'69139000']);
        self::assertSame('69139000',$result['ncm']);
    }

    public function test_partial_unknown_codes_require_positive_reference_before_automatic_emission(): void
    {
        $this->install('partial');
        $service=app(FiscalProductClassificationService::class);
        foreach ([['ncm'=>'69139000'],['ncm'=>'61091000','cest'=>'2805900']] as $codes) {
            self::assertSame($codes,$service->validate($codes));
            try {
                $service->assertForEmission($codes,'2026-10-26');
                self::fail('Classificação desconhecida não pode ser aplicada automaticamente.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('sem referência oficial vigente',$exception->getMessage());
            }
        }
    }

    public function test_current_complete_snapshot_does_not_prove_historical_absence(): void
    {
        $manifest=$this->manifest();
        $manifest['coverage']['ncm']=['status'=>'complete','snapshot_date'=>'2026-10-25'];
        app(FiscalCatalogService::class)->import($manifest);
        self::assertSame(['ncm'=>'69139000'],app(FiscalProductClassificationService::class)
            ->validate(['ncm'=>'69139000'],null,'2025-01-01'));
    }

    public function test_ordinary_edit_preserves_unchanged_legacy_classifications_and_group(): void
    {
        $this->install();
        $product=Product::query()->create($this->payload(['sku'=>'LEGACY-1','ncm'=>'1234','cest'=>'ANTIGO',
            'tax_defaults'=>['icms_csosn'=>'102','cfop_outbound_internal'=>'5102']]));
        $this->actingAs($this->admin())->put(route('products.update',$product),$this->payload([
            'ncm'=>'1234','cest'=>'ANTIGO','sale_price'=>'21.00',
        ]))->assertRedirect(route('products.index'));
        $product->refresh();
        self::assertSame('1234',$product->ncm);
        self::assertSame('ANTIGO',$product->cest);
        self::assertSame('21.00',$product->sale_price);
        self::assertSame('5102',$product->tax_defaults['cfop_outbound_internal']);
    }

    public function test_new_association_cannot_enable_an_unreviewed_catalog_group(): void
    {
        $group=FiscalTaxGroup::query()->create(['name'=>'Modelo pendente','kind'=>'products','is_active'=>true,
            'catalog_version'=>'2026.10.26','catalog_status'=>'template']);
        $this->actingAs($this->admin())->postJson(route('products.store'),$this->payload([
            'fiscal_tax_group_id'=>$group->id,
        ]))->assertUnprocessable()->assertJsonValidationErrors('fiscal_tax_group_id');
        $this->assertDatabaseCount('products',0);
        $group->update(['catalog_status'=>'reviewed','fiscal_reviewed_at'=>now()]);
        $this->post(route('products.store'),$this->payload(['fiscal_tax_group_id'=>$group->id]))
            ->assertRedirect(route('products.index'));
        self::assertSame($group->id,Product::query()->firstOrFail()->fiscalTaxGroup->id);
    }

    public function test_product_search_is_authorized_read_only_and_filters_cest_by_ncm(): void
    {
        $this->install();
        $this->actingAs($this->admin());
        $this->getJson(route('products.fiscal-classifications',['kind'=>'ncm','q'=>'6109']))
            ->assertOk()->assertJsonPath('items.0.code','61091000');
        $this->getJson(route('products.fiscal-classifications',['kind'=>'cest','q'=>'03','ncm'=>'61091000']))
            ->assertOk()->assertJsonCount(0,'items');
        $this->getJson(route('products.fiscal-classifications',['kind'=>'cest','q'=>'03','ncm'=>'22030000']))
            ->assertOk()->assertJsonPath('items.0.code','0302100');
        $this->assertDatabaseCount('products',0);
        $this->actingAs(User::query()->create(['name'=>'Sem acesso','email'=>'no-products@example.test',
            'password'=>'test-password','role'=>'employee','is_active'=>true,'permissions'=>['customers']]));
        $this->getJson(route('products.fiscal-classifications',['kind'=>'ncm','q'=>'6109']))->assertForbidden();
    }

    public function test_new_catalog_emission_requires_classification_reference_and_validity(): void
    {
        $manifest=$this->manifest('partial');
        $manifest['entries'][0]['valid_until']='2026-09-30';
        app(FiscalCatalogService::class)->import($manifest);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('NCM não consta como vigente');
        app(FiscalProductClassificationService::class)->assertForEmission(['ncm'=>'61091000'],'2026-10-26');
    }

    public function test_missing_reference_blocks_catalog_emission_but_does_not_change_legacy_creation(): void
    {
        $service=app(FiscalProductClassificationService::class);
        self::assertSame('61091000',$service->validate(['ncm'=>'61091000'])['ncm']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Instale o catálogo fiscal');
        $service->assertForEmission(['ncm'=>'61091000']);
    }

    public function test_new_invalid_cest_is_rejected_without_a_catalog(): void
    {
        $this->expectException(ValidationException::class);
        app(FiscalProductClassificationService::class)->validate(['cest'=>'123']);
    }

    public function test_quick_creation_cannot_bypass_official_classification_validation(): void
    {
        $this->install();
        $this->actingAs($this->admin());
        $this->postJson(route('quick-catalog.store',['kind'=>'products']),$this->payload(['ncm'=>'99999999']))
            ->assertUnprocessable()->assertJsonValidationErrors('ncm');
        $this->postJson(route('quick-catalog.store',['kind'=>'products']),$this->payload(['ncm'=>'A61091000']))
            ->assertUnprocessable()->assertJsonValidationErrors('ncm');
        $this->postJson(route('quick-catalog.store',['kind'=>'products']),$this->payload(['ncm'=>['61091000']]))
            ->assertUnprocessable()->assertJsonValidationErrors('ncm');
        $this->postJson(route('quick-catalog.store',['kind'=>'products']),$this->payload(['ncm'=>'6109.10.00']))
            ->assertCreated()->assertJsonPath('item.ncm','61091000');
        $this->assertDatabaseCount('products',1);
    }

    public function test_state_specific_reference_is_not_applied_as_a_national_classification(): void
    {
        $manifest=$this->manifest('partial');
        $manifest['entries'][2]['uf']='SP';
        app(FiscalCatalogService::class)->import($manifest);
        $service=app(FiscalProductClassificationService::class);
        // This installation is BA. Its CEST cannot be validated or rejected by a SP-only table.
        $codes=['ncm'=>'61091000','cest'=>'0302100'];
        self::assertSame($codes,$service->validate($codes));
        self::assertSame([],$service->search('cest','03','22030000'));
        self::assertNull($service->trace($codes)['entries']['cest']['reference']);
        self::assertSame('SP',$service->trace($codes,null,'SP')['entries']['cest']['reference']['uf']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CEST informado não corresponde');
        $service->assertForEmission($codes,'2026-10-26','SP');
    }
}
