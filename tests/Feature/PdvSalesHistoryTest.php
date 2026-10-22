<?php

namespace Tests\Feature;

use App\Models\FiscalDocumentJob;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PdvSalesHistoryTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\WithConfiguredCompany;

    private function operator(string $email='cashier@example.com'): User
    {
        return User::query()->create([
            'name'=>'Operador de caixa',
            'email'=>$email,
            'password'=>'SenhaTeste123',
            'role'=>'operator',
            'permissions'=>['pdv'],
            'is_active'=>true,
        ]);
    }

    private function sale(User $operator,array $attrs=[]): Sale
    {
        return Sale::query()->create(array_merge([
            'source'=>'pdv',
            'user_id'=>$operator->id,
            'operation_type'=>'sale',
            'operation_date'=>today(),
            'status'=>'completed',
            'subtotal'=>'29.90',
            'discount_total'=>'0',
            'total'=>'29.90',
            'consumer_name'=>'Consumidor do caixa',
            'completed_at'=>now(),
        ],$attrs));
    }

    public function test_sales_button_opens_local_modal_without_navigating_to_admin_sales(): void
    {
        $cashier=$this->operator();
        $this->actingAs($cashier)->get(route('pdv.index'))
            ->assertOk()
            ->assertSee('id="pdvSalesLink"',false)
            ->assertSee('aria-controls="pdvSalesModal"',false)
            ->assertSee('id="pdvSalesModal"',false)
            ->assertSee('data-sales-url="'.route('pdv.sales.history').'"',false)
            ->assertSee('js/pdv-sales.js',false)
            ->assertDontSee('id="pdvSalesLink" href="'.route('sales.index').'"',false);
    }

    public function test_api_defaults_to_only_today_pdv_sales(): void
    {
        $cashier=$this->operator();
        $todaySale=$this->sale($cashier);
        $yesterday=$this->sale($cashier,[
            'consumer_name'=>'Ontem',
            'operation_date'=>today()->subDay(),
            'completed_at'=>now()->subDay(),
        ]);
        $office=$this->sale($cashier,['source'=>'sales','consumer_name'=>'Venda administrativa']);
        $quote=$this->sale($cashier,['operation_type'=>'quote','consumer_name'=>'Orçamento']);

        $this->actingAs($cashier)->getJson(route('pdv.sales.history'))
            ->assertOk()
            ->assertJsonPath('pagination.total',1)
            ->assertJsonCount(1,'items')
            ->assertJsonPath('items.0.id',$todaySale->id)
            ->assertJsonPath('items.0.customer','Consumidor do caixa')
            ->assertJsonPath('items.0.status_label','Concluída');

        self::assertNotSame($todaySale->id,$yesterday->id);
        self::assertNotSame($todaySale->id,$office->id);
        self::assertNotSame($todaySale->id,$quote->id);
    }

    public function test_filters_by_date_cancelled_status_operator_and_sale_number(): void
    {
        $cashier=$this->operator();
        $other=$this->operator('other@example.com');
        $cancelled=$this->sale($cashier,[
            'status'=>'cancelled',
            'consumer_name'=>'Cliente histórico',
            'operation_date'=>today()->subDays(2),
            'completed_at'=>now()->subDays(2),
        ]);
        $this->sale($other,[
            'operation_date'=>today()->subDays(2),
            'completed_at'=>now()->subDays(2),
        ]);
        $query=[
            'from'=>today()->subDays(3)->toDateString(),
            'to'=>today()->toDateString(),
            'status'=>'cancelled',
            'operator'=>'mine',
            'q'=>'#'.$cancelled->id,
        ];

        $this->actingAs($cashier)->getJson(route('pdv.sales.history',$query))
            ->assertOk()
            ->assertJsonPath('pagination.total',1)
            ->assertJsonPath('items.0.id',$cancelled->id)
            ->assertJsonPath('items.0.status_label','Cancelada');
    }

    public function test_query_exposes_fiscal_status_and_pdv_receipt_but_not_administrator_url(): void
    {
        $cashier=$this->operator();
        $sale=$this->sale($cashier);
        $job=FiscalDocumentJob::query()->create([
            'document_type'=>'nfce','sale_id'=>$sale->id,
            'status'=>'authorized','environment'=>'homologation',
            'series'=>1,'document_number'=>15,
            'xml_path'=>'fiscal/nfce/1/authorized.xml',
        ]);

        $this->actingAs($cashier)->getJson(route('pdv.sales.history'))
            ->assertOk()
            ->assertJsonPath('items.0.fiscal_status','Autorizada')
            ->assertJsonPath('items.0.receipt_url',route('pdv.receipt',['sale'=>$sale->id,'print'=>0]))
            ->assertJsonPath('items.0.receipt_preview_url',route('pdv.sales.receipt.preview',$sale))
            ->assertJsonPath('items.0.danfe_url',route('pdv.nfce.danfe',$job));
    }

    public function test_history_receipt_uses_the_same_thermal_modal_without_repeating_checkout(): void
    {
        $cashier=$this->operator();
        $sale=$this->sale($cashier);
        $sale->items()->create([
            'item_type'=>'product','product_name'=>'Camiseta de teste',
            'product_sku'=>'TESTE-1','quantity'=>'1.000',
            'unit_price'=>'29.90','discount'=>'0.00','line_total'=>'29.90',
        ]);

        $this->actingAs($cashier)
            ->get(route('pdv.sales.receipt.preview',$sale))
            ->assertOk()
            ->assertViewIs('pdv.partials.receipt-modal')
            ->assertHeader('Cache-Control','no-store, private')
            ->assertSee('id="pdvHistoryReceiptModal"',false)
            ->assertSee('data-receipt-after-checkout="0"',false)
            ->assertSee('data-pdv-receipt-print',false)
            ->assertSee('id="pdvHistoryReceiptPrint"',false)
            ->assertSee(route('pdv.receipt',['sale'=>$sale,'print'=>0]),false)
            ->assertSee('pdv-receipt-paper-caption')
            ->assertSee('Camiseta de teste')
            ->assertSee('R$ 29,90')
            ->assertSee('Comprovante da venda')
            ->assertSee('Voltar às vendas')
            ->assertDontSee('Venda efetuada com sucesso')
            ->assertDontSee('window.print()',false);
        self::assertSame(1,Sale::count());
    }

    public function test_history_cancelled_sale_does_not_display_a_success_message(): void
    {
        $cashier=$this->operator();
        $sale=$this->sale($cashier,['status'=>'cancelled']);
        $this->actingAs($cashier)
            ->get(route('pdv.sales.receipt.preview',$sale))
            ->assertOk()
            ->assertSee('Venda cancelada')
            ->assertDontSee('Venda efetuada com sucesso')
            ->assertDontSee('Preparar NFC-e');
    }

    public function test_preview_rejects_non_pdv_sales_quotes_and_unauthorized_users(): void
    {
        $cashier=$this->operator();
        $sale=$this->sale($cashier);
        $differentSource=$this->sale($cashier,['source'=>'sales']);
        $quote=$this->sale($cashier,['operation_type'=>'quote']);
        $this->actingAs($cashier);
        $this->get(route('pdv.sales.receipt.preview',$differentSource))->assertNotFound();
        $this->get(route('pdv.sales.receipt.preview',$quote))->assertNotFound();
        $stock=User::query()->create([
            'name'=>'Estoque','email'=>'stock-preview@example.test',
            'password'=>'SenhaTeste123','role'=>'operator',
            'permissions'=>['products'],'is_active'=>true,
        ]);
        $this->actingAs($stock)
            ->get(route('pdv.sales.receipt.preview',$sale))->assertForbidden();
    }

    public function test_empty_results_and_invalid_date_range_are_handled(): void
    {
        $cashier=$this->operator();
        $this->actingAs($cashier)->getJson(route('pdv.sales.history'))
            ->assertOk()->assertJsonCount(0,'items')->assertJsonPath('pagination.total',0);

        $this->getJson(route('pdv.sales.history',[
            'from'=>today()->subDays(120)->toDateString(),
            'to'=>today()->toDateString(),
        ]))->assertUnprocessable()->assertJsonValidationErrors('from');

        $this->getJson(route('pdv.sales.history',[
            'from'=>today()->toDateString(),
            'to'=>today()->subDay()->toDateString(),
        ]))->assertUnprocessable()->assertJsonValidationErrors('from');
    }

    public function test_sales_history_is_unavailable_to_users_without_pdv_permission(): void
    {
        $user=User::query()->create([
            'name'=>'Estoque','email'=>'warehouse@example.com',
            'password'=>'SenhaTeste123','role'=>'operator',
            'permissions'=>['products'],'is_active'=>true,
        ]);

        $this->actingAs($user)->getJson(route('pdv.sales.history'))->assertForbidden();
    }
}
