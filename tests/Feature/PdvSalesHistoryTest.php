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
            ->assertJsonPath('items.0.danfe_url',route('pdv.nfce.danfe',$job));
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
