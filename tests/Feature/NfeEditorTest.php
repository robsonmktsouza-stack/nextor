<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\NfeDraft;
use App\Models\OperationNature;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NfeEditorTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::query()->create([
            'name'=>'Administrador NF-e',
            'email'=>'nfe-admin@example.com',
            'password'=>'senhaSegura123',
            'role'=>'admin',
            'is_active'=>true,
        ]);
    }

    private function nature(): OperationNature
    {
        return OperationNature::query()->create([
            'name'=>'Venda de mercadoria',
            'operation_type'=>'outbound',
            'purpose'=>'normal',
            'cfop_internal'=>'5102',
            'cfop_interstate'=>'6102',
            'override_product_cfop'=>true,
            'presence_default'=>'not_applicable',
            'move_stock'=>true,
            'generate_finance'=>true,
            'allow_referenced_document'=>true,
            'is_active'=>true,
        ]);
    }

    private function customer(): Customer
    {
        return Customer::query()->create([
            'name'=>'Cliente Teste Ltda',
            'document'=>'12345678000190',
            'is_customer'=>true,
            'state'=>'BA',
            'city'=>'Salvador',
            'address'=>'Rua Teste',
            'address_number'=>'100',
            'district'=>'Centro',
            'final_consumer'=>false,
            'ie_indicator'=>'contributor',
            'state_registration'=>'123456789',
        ]);
    }

    private function product(array $override=[]): Product
    {
        return Product::query()->create(array_merge([
            'sku'=>'PROD-NFE-001',
            'name'=>'Produto NF-e',
            'unit'=>'UN',
            'sale_price'=>100,
            'cost_price'=>50,
            'origin'=>'0',
            'ncm'=>'12345678',
            'tax_defaults'=>[
                'icms_csosn_default'=>'102',
                'pis_cst_default'=>'49',
                'cofins_cst_default'=>'49',
                'ipi_cst_default'=>'99',
                'ibs_cst_default'=>'000',
                'cbs_cst_default'=>'000',
            ],
            'is_active'=>true,
        ],$override));
    }

    public function test_new_nfe_opens_mapped_full_screen_editor(): void
    {
        $this->nature();
        $this->customer();
        $this->product();

        $this->actingAs($this->admin())
            ->get(route('fiscal.nfe.create'))
            ->assertOk()
            ->assertSee('Criando nova NF-e')
            ->assertSee('Dados gerais')
            ->assertSee('Dados do destinatário')
            ->assertSee('Lista de produtos')
            ->assertSee('Retenções de impostos')
            ->assertSee('Fatura e duplicatas')
            ->assertSee('Informações de pagamento')
            ->assertSee('Dados do transporte')
            ->assertSee('Informações de compras')
            ->assertSee('Informações de agropecuária')
            ->assertSee('Campos de uso livre')
            ->assertSee('Outras informações')
            ->assertSee('Dados tributários')
            ->assertSee('IBS / CBS')
            ->assertSee('Dados de controle de ST')
            ->assertSee('Dados de importação')
            ->assertSee('Dados de exportação / drawback')
            ->assertSee('Produtos específicos')
            ->assertSee('Rastreabilidade')
            ->assertSee('Disponível após a integração ACBr');
    }

    public function test_operation_nature_can_be_created(): void
    {
        $this->actingAs($this->admin())
            ->post(route('fiscal.nfe.natures.store'),[
                'name'=>'Venda interestadual',
                'operation_type'=>'outbound',
                'purpose'=>'normal',
                'cfop_internal'=>'5102',
                'cfop_interstate'=>'6102',
                'presence_default'=>'not_applicable',
                'override_product_cfop'=>'1',
                'move_stock'=>'1',
                'generate_finance'=>'1',
                'allow_referenced_document'=>'1',
                'is_active'=>'1',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('operation_natures',[
            'name'=>'Venda interestadual',
            'cfop_internal'=>'5102',
            'cfop_interstate'=>'6102',
            'is_active'=>1,
        ]);
    }

    public function test_nfe_draft_persists_mapped_item_and_snapshots(): void
    {
        $nature=$this->nature();
        $customer=$this->customer();
        $product=$this->product();

        $items=[[
            'product_id'=>$product->id,
            'product_name'=>$product->name,
            'product_sku'=>$product->sku,
            'quantity'=>2,
            'unit_price'=>100,
            'freight'=>10,
            'insurance'=>0,
            'other_expenses'=>5,
            'discount'=>15,
            'cfop'=>'5102',
            'origin'=>'0',
            'ean_gtin'=>'',
            'unit'=>'UN',
            'tax_unit'=>'UN',
            'ncm'=>'12345678',
            'cest'=>'',
            'ipi_exception'=>'',
            'fiscal_benefit_code'=>'',
            'purchase_order'=>'PED-1',
            'purchase_order_item'=>'1',
            'notes'=>'Teste',
            'tax_data'=>[
                'icms_csosn'=>'102',
                'pis_cst'=>'49',
                'cofins_cst'=>'49',
                'ipi_cst'=>'99',
                'ibs_cst'=>'000',
                'cbs_cst'=>'000',
            ],
            'special_data'=>['traceability'=>'Lote teste'],
        ]];

        $response=$this->actingAs($this->admin())
            ->post(route('fiscal.nfe.store'),[
                'operation_nature_id'=>$nature->id,
                'customer_id'=>$customer->id,
                'operation_type'=>'outbound',
                'destination'=>'auto',
                'presence'=>'not_applicable',
                'purpose'=>'normal',
                'final_consumer'=>'0',
                'issue_date'=>'2026-10-03',
                'issue_time'=>'10:30',
                'government_purchase'=>'0',
                'advance_payment'=>'0',
                'different_delivery'=>'0',
                'freight_mode'=>'sender',
                'items_json'=>json_encode($items),
                'duplicates_json'=>'[]',
                'payments_json'=>json_encode([['payment_method'=>'pix','amount'=>200]]),
                'references_json'=>'[]',
                'custom_fields_json'=>json_encode(['purchase_info'=>'Pedido de teste']),
                'additional_info'=>'Informação complementar',
            ]);

        $draft=NfeDraft::query()->firstOrFail();

        $response->assertRedirect(route('fiscal.nfe.edit',$draft));
        $this->assertSame('draft',$draft->status);
        $this->assertNull($draft->document_number);
        $this->assertSame('Cliente Teste Ltda',data_get($draft->recipient_snapshot,'name'));
        $this->assertEquals(200.0,(float)data_get($draft->totals,'total'));
        $this->assertDatabaseHas('nfe_draft_items',[
            'nfe_draft_id'=>$draft->id,
            'product_id'=>$product->id,
            'cfop'=>'5102',
            'ncm'=>'12345678',
            'line_total'=>200,
        ]);
    }

    public function test_validate_action_saves_current_form_before_checking(): void
    {
        $nature=$this->nature();
        $customer=$this->customer();
        $product=$this->product();

        $items=[[
            'product_id'=>$product->id,
            'product_name'=>$product->name,
            'product_sku'=>$product->sku,
            'quantity'=>1,
            'unit_price'=>100,
            'cfop'=>'5102',
            'origin'=>'0',
            'unit'=>'UN',
            'ncm'=>'12345678',
            'tax_data'=>[
                'icms_csosn'=>'102',
                'pis_cst'=>'49',
                'cofins_cst'=>'49',
            ],
        ]];

        $this->actingAs($this->admin())
            ->post(route('fiscal.nfe.store'),[
                'operation_nature_id'=>$nature->id,
                'customer_id'=>$customer->id,
                'operation_type'=>'outbound',
                'destination'=>'auto',
                'presence'=>'not_applicable',
                'purpose'=>'normal',
                'final_consumer'=>'0',
                'issue_date'=>'2026-10-03',
                'issue_time'=>'10:30',
                'government_purchase'=>'0',
                'advance_payment'=>'0',
                'different_delivery'=>'0',
                'freight_mode'=>'none',
                'items_json'=>json_encode($items),
                'duplicates_json'=>'[]',
                'payments_json'=>'[]',
                'references_json'=>'[]',
                'custom_fields_json'=>'{}',
                'after_save'=>'validate',
            ])
            ->assertRedirect();

        $draft=NfeDraft::query()->firstOrFail();
        $this->assertNotNull($draft->validated_at);
    }

    public function test_fiscal_nfe_toolbar_opens_editor_and_drafts(): void
    {
        $this->actingAs($this->admin())
            ->get(route('fiscal.index',['tab'=>'nfe','month'=>'2026-10']))
            ->assertOk()
            ->assertSee(route('fiscal.nfe.create'),false)
            ->assertSee(route('fiscal.nfe.drafts.index'),false)
            ->assertSee(route('fiscal.nfe.natures.index'),false);
    }
}
