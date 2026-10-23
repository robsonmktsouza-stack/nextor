<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithConfiguredCompany;
use Tests\TestCase;

final class QuickCatalogTest extends TestCase
{
    use RefreshDatabase;
    use WithConfiguredCompany;

    private function admin(): User
    {
        return User::query()->create([
            'name'=>'Gerente de cadastro','email'=>'catalog@example.test',
            'password'=>'safe-test-password','role'=>'admin','is_active'=>true,
        ]);
    }

    public function test_search_is_read_only_and_returns_existing_products(): void
    {
        $this->actingAs($this->admin());
        Product::query()->create([
            'name'=>'Camiseta algodão','sku'=>'CAMIS-100','unit'=>'UN',
            'sale_price'=>'49.90','cost_price'=>'22.00','is_active'=>true,
        ]);
        $this->getJson(route('quick-catalog.search',['kind'=>'products','q'=>'CAMIS']))
            ->assertOk()->assertJsonPath('items.0.name','Camiseta algodão')
            ->assertJsonPath('items.0.sku','CAMIS-100');
        $this->getJson(route('quick-catalog.search',['kind'=>'products','q'=>'não existe']))
            ->assertOk()->assertJsonCount(0,'items');
        $this->assertDatabaseCount('products',1);
    }

    public function test_quick_product_creation_persists_a_real_product_and_requires_valid_fields(): void
    {
        $this->actingAs($this->admin());
        $data=[
            'name'=>'Produto novo no modal','sku'=>'NOVO-001','unit'=>'UN',
            'usage_type'=>'resale','cost_price'=>'15.00','sale_price'=>'27.50',
            'origin'=>'0','control_stock'=>true,'ncm'=>'61091000',
        ];
        $result=$this->postJson(route('quick-catalog.store',['kind'=>'products']),$data)
            ->assertCreated()->assertJsonPath('item.name','Produto novo no modal')
            ->assertJsonPath('item.sku','NOVO-001')
            ->assertJsonPath('item.ncm','61091000');
        $this->assertDatabaseHas('products',[
            'id'=>$result->json('item.id'),'name'=>'Produto novo no modal',
            'stock_quantity'=>'0.000','control_stock'=>1,
        ]);
        $this->postJson(route('quick-catalog.store',['kind'=>'products']),$data)
            ->assertUnprocessable()->assertJsonValidationErrors('sku');
        $this->postJson(route('quick-catalog.store',['kind'=>'products']),
            array_merge($data,['sku'=>'NOVO-002','ncm'=>'123']))
            ->assertUnprocessable()->assertJsonValidationErrors('ncm');
        $this->assertDatabaseCount('products',1);
    }

    public function test_supplier_created_in_modal_is_available_for_a_real_purchase(): void
    {
        $this->actingAs($this->admin());
        $result=$this->postJson(route('quick-catalog.store',['kind'=>'suppliers']),[
            'name'=>'Distribuidora Nova','document'=>'39.323.356/0001-00',
            'state'=>'SP','city'=>'São Paulo','ie_indicator'=>'contributor',
        ])->assertCreated()->assertJsonPath('item.name','Distribuidora Nova');

        $supplier=Customer::findOrFail($result->json('item.id'));
        self::assertTrue($supplier->is_supplier);
        self::assertFalse($supplier->is_customer);
        $this->getJson(route('quick-catalog.search',['kind'=>'suppliers','q'=>'Distribuidora']))
            ->assertOk()->assertJsonCount(1,'items');
        $this->postJson(route('quick-catalog.store',['kind'=>'suppliers']),[
            'name'=>'Mesma empresa','document'=>'39323356000100',
            'ie_indicator'=>'non_contributor',
        ])->assertUnprocessable()->assertJsonValidationErrors('document');
        $this->assertDatabaseCount('customers',1);
    }

    public function test_customer_creation_is_an_explicit_action_and_keeps_role(): void
    {
        $this->actingAs($this->admin());
        $this->getJson(route('quick-catalog.search',['kind'=>'customers','q'=>'Nova Loja']))->assertOk();
        $this->assertDatabaseCount('customers',0);
        $this->postJson(route('quick-catalog.store',['kind'=>'customers']),[
            'name'=>'Nova Loja','ie_indicator'=>'non_contributor',
        ])->assertCreated()->assertJsonPath('item.final_consumer',false);
        $this->assertDatabaseHas('customers',['name'=>'Nova Loja','is_customer'=>1,'is_supplier'=>0]);
    }

    public function test_carrier_is_independent_of_customer_and_visible_to_nfe_transport_selector(): void
    {
        $this->actingAs($this->admin());
        $result=$this->postJson(route('quick-catalog.store',['kind'=>'carriers']),[
            'name'=>'Transportes NF-e','document'=>'11777888000190',
            'ie_indicator'=>'contributor','state'=>'BA','city'=>'Salvador',
        ])->assertCreated()->assertJsonPath('item.name','Transportes NF-e');
        $this->assertDatabaseHas('customers',[
            'id'=>$result->json('item.id'),'is_carrier'=>1,'is_customer'=>0,'is_supplier'=>0,
        ]);
        $this->getJson(route('quick-catalog.search',['kind'=>'carriers','q'=>'Transportes']))
            ->assertOk()->assertJsonCount(1,'items');
        $this->get(route('fiscal.nfe.create'))->assertOk()
            ->assertSee('data-quick-create="carriers"',false)
            ->assertSee('Transportes NF-e');
    }

    public function test_employee_may_search_but_cannot_create_catalog_without_permission(): void
    {
        $user=User::query()->create([
            'name'=>'Operador de Compras','email'=>'catalog-operator@example.test',
            'password'=>'safe-test-password','role'=>'operator','is_active'=>true,
            'permissions'=>['purchases'],
        ]);
        $this->actingAs($user);
        $this->getJson(route('quick-catalog.search',['kind'=>'products']))
            ->assertOk();
        $this->postJson(route('quick-catalog.store',['kind'=>'products']),[
            'name'=>'Não pode criar','unit'=>'UN','usage_type'=>'resale',
            'cost_price'=>'1.00','sale_price'=>'2.00','origin'=>'0','control_stock'=>true,
        ])->assertForbidden();
        $this->postJson(route('quick-catalog.store',['kind'=>'suppliers']),[
            'name'=>'Não pode cadastrar','ie_indicator'=>'non_contributor',
        ])->assertForbidden();
        $this->assertDatabaseCount('products',0);
        $this->assertDatabaseCount('customers',0);
    }

    public function test_selectors_offer_modal_creation_in_purchases_sales_and_nfe(): void
    {
        $this->actingAs($this->admin());
        $this->get(route('purchases.create'))
            ->assertOk()->assertSee('data-quick-create="suppliers"',false)
            ->assertSee('id="erpCatalogDialog"',false)
            ->assertSee('data-purchase-add',false);
        $this->get(route('sales.create'))
            ->assertOk()->assertSee('data-quick-create="customers"',false)
            ->assertSee('id="erpCatalogDialog"',false);
        $views=file_get_contents(resource_path('views/fiscal/nfe/editor.blade.php'));
        self::assertStringContainsString('data-quick-create="customers"',$views);
        self::assertStringContainsString('id="nfeQuickCreateProduct"',$views);
        $script=file_get_contents(public_path('js/purchases.js'));
        self::assertStringContainsString("LumeronCatalog?.pick('products'",$script);
        self::assertStringContainsString("LumeronCatalog?.create('suppliers'",$script);
        $nfe=file_get_contents(public_path('js/nfe-editor.js'));
        self::assertStringContainsString("LumeronCatalog?.create('products'",$nfe);
        $core=file_get_contents(public_path('js/erp.js'));
        self::assertStringContainsString('ui-select-catalog-create',$core);
    }
}
