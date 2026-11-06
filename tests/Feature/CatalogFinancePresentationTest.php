<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\FinancialCategory;
use App\Models\FinancialEntry;
use App\Models\FiscalTaxGroup;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\FinancialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class CatalogFinancePresentationTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\WithConfiguredCompany;

    private function admin(): User
    {
        return User::query()->create([
            'name'=>'Responsável', 'email'=>'catalog-finance-audit@example.test',
            'password'=>'senhaSegura123', 'role'=>'admin', 'is_active'=>true,
        ]);
    }

    public function test_cnpj_lookup_keeps_connection_diagnostics_out_of_the_response_and_reports_them(): void
    {
        $this->app->instance('env','local');
        Exceptions::fake();
        $attempts=0;
        Http::fake(static function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('cURL error 60: private-ca.pem in C:\\private\\certificates');
        });

        $this->actingAs($this->admin())->getJson(route('customers.lookup-cnpj',['cnpj'=>'11222333000181']))
            ->assertStatus(503)
            ->assertJsonPath('message','O serviço de consulta de CNPJ não respondeu. Tente novamente em alguns instantes.')
            ->assertDontSee('private-ca.pem')->assertDontSee('cURL')->assertDontSee('PHP local');

        Exceptions::assertReported(static fn(ConnectionException $error) => str_contains($error->getMessage(),'private-ca.pem'));
        self::assertSame(2,$attempts);
    }

    public function test_successful_cnpj_lookup_preserves_the_fields_needed_to_fill_the_customer(): void
    {
        Http::fake(['brasilapi.com.br/*'=>Http::response([
            'cnpj'=>'11222333000181','razao_social'=>'Fornecedor de Teste',
            'logradouro'=>'Rua Comercial','municipio'=>'Salvador','uf'=>'BA',
        ])]);
        $this->actingAs($this->admin())->getJson(route('customers.lookup-cnpj',['cnpj'=>'11222333000181']))
            ->assertOk()->assertJsonPath('razao_social','Fornecedor de Teste')
            ->assertJsonPath('municipio','Salvador');
    }

    public function test_unknown_stock_operation_is_readable_without_rewriting_its_audit_record(): void
    {
        $user=$this->admin();
        $product=Product::query()->create([
            'name'=>'Produto de Estoque','sku'=>'AUD-STOCK','unit'=>'UN',
            'cost_price'=>'10.00','sale_price'=>'20.00','is_active'=>true,
        ]);
        $movement=StockMovement::query()->create([
            'product_id'=>$product->id,'user_id'=>$user->id,'type'=>'legacy_internal_adjustment',
            'quantity_delta'=>'2.000','previous_quantity'=>'1.000','new_quantity'=>'3.000',
            'reason'=>'Conferência de inventário',
        ]);
        $movement->forceFill(['created_at'=>now()])->save();
        $original=$movement->fresh()->getRawOriginal();

        $this->actingAs($user)->get(route('stock.index'))->assertOk()
            ->assertSee('Outra movimentação')->assertSee('Conferência de inventário')
            ->assertDontSee('legacy_internal_adjustment');
        self::assertSame($original,$movement->fresh()->getRawOriginal());
    }

    public function test_purchase_payable_explains_the_correct_origin_without_changing_the_entry(): void
    {
        $user=$this->admin();
        $supplier=Customer::query()->create(['name'=>'Fornecedor de Teste','is_supplier'=>true]);
        $purchase=Purchase::query()->create([
            'supplier_id'=>$supplier->id,'created_by'=>$user->id,'status'=>'received',
            'operation_date'=>today(),'total'=>'100.00',
        ]);
        $entry=FinancialEntry::query()->create([
            'type'=>'payable','status'=>'open','created_by'=>$user->id,
            'category_id'=>FinancialCategory::query()->where('type','expense')->firstOrFail()->id,
            'purchase_id'=>$purchase->id,'description'=>'Compra de mercadorias',
            'issue_date'=>today(),'due_date'=>today(),'amount'=>'100.00','paid_amount'=>'0.00',
        ]);
        $before=$entry->fresh()->getRawOriginal();
        try {
            app(FinancialService::class)->cancelManual($entry);
            self::fail('A conta vinculada deve continuar protegida contra cancelamento manual.');
        } catch (ValidationException $error) {
            self::assertSame('Cancele a venda ou a compra de origem para cancelar este lançamento.',$error->errors()['entry'][0]);
        }
        self::assertSame($before,$entry->fresh()->getRawOriginal());
    }

    public function test_product_shows_review_status_without_exposing_catalog_versions(): void
    {
        $group=FiscalTaxGroup::query()->create([
            'name'=>'Revenda','kind'=>'products','is_active'=>true,
            'catalog_version'=>'2026.10.26.private-metadata','catalog_status'=>'template',
        ]);
        $response=$this->actingAs($this->admin())->get(route('products.create'));
        $response->assertOk()->assertSee('Aguardando revisão fiscal')
            ->assertDontSee('2026.10.26.private-metadata')->assertDontSee('Modelo pendente de revisão');
        self::assertSame('template',$group->fresh()->catalog_status);
        self::assertNull($group->fresh()->fiscal_reviewed_at);
    }
}
