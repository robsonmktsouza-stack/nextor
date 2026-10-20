<?php

namespace Tests\Feature;

use App\Models\Sale;
use App\Models\User;
use App\Models\PaymentMethod;
use App\Models\FinancialEntry;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SaleReceiptReuseTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\WithConfiguredCompany;

    private function operator(array $permissions=['sales']): User
    {
        return User::create([
            'name'=>'Vendedor de teste',
            'email'=>'vendedor-'.implode('-',$permissions).'@example.test',
            'password'=>'senhaSegura123',
            'role'=>'operator',
            'permissions'=>$permissions,
            'is_active'=>true,
        ]);
    }

    private function sale(User $user, string $type='sale', string $source='manual'): Sale
    {
        $sale=Sale::create([
            'user_id'=>$user->id,
            'operation_type'=>$type,
            'source'=>$source,
            'operation_date'=>now()->toDateString(),
            'final_consumer'=>true,
            'status'=>'completed',
            'subtotal'=>'70.00',
            'discount_total'=>'5.00',
            'total'=>'65.00',
            'completed_at'=>now(),
            'notes'=>'Entregar no balcão',
        ]);
        $sale->items()->create([
            'item_type'=>'product',
            'product_name'=>'Produto para balcão',
            'product_sku'=>'BAL-001',
            'quantity'=>'2.000','unit_price'=>'35.00',
            'discount'=>'5.00','line_total'=>'65.00',
        ]);
        return $sale;
    }

    public function test_regular_sale_uses_the_same_thermal_receipt_without_pdv_permission(): void
    {
        $user=$this->operator(['sales']);
        $sale=$this->sale($user);
        PaymentMethod::firstOrCreate(['code'=>'pix'],[
            'name'=>'PIX','kind'=>'pix','is_active'=>true,'pdv_enabled'=>true,
        ]);
        $sale->payments()->create([
            'installment'=>1,'amount'=>'65.00',
            'payment_method'=>'pix','receivable'=>false,
        ]);

        $response=$this->actingAs($user)->get(route('sales.receipt',['sale'=>$sale,'print'=>0]));
        $response->assertOk()->assertViewIs('pdv.receipt')
            ->assertSee('COMPROVANTE DE VENDA')
            ->assertSee('SEM VALOR FISCAL')
            ->assertSee('BAL-001')
            ->assertSee('Produto para balcão')
            ->assertSee('65,00')
            ->assertSee('PIX')
            ->assertSee('Voltar à venda')
            ->assertSee('Imprimir comprovante interno')
            ->assertSee('let automatic=false',false)
            ->assertDontSee('Voltar ao PDV')
            ->assertDontSee('Preparar NFC-e desta venda')
            ->assertDontSee('NFC-e ainda não preparada');
        self::assertSame(route('sales.show',$sale),$response->viewData('receiptOrigin')==='sales'
            ? route('sales.show',$sale) : null);

        $this->get(route('sales.show',$sale))->assertOk()
            ->assertSee('Comprovante de venda')
            ->assertSee(route('sales.receipt',['sale'=>$sale,'print'=>0]),false);
        $this->get(route('sales.index'))->assertOk()
            ->assertSee('Imprimir comprovante de venda');

        self::assertSame(1,Sale::count());
        self::assertSame(0,StockMovement::count());
        self::assertSame(0,FinancialEntry::count());
    }

    public function test_receipt_is_not_exposed_for_quotes_and_does_not_change_pdv_source_rules(): void
    {
        $user=$this->operator(['sales']);
        $quote=$this->sale($user,'quote');

        $this->actingAs($user)
            ->get(route('sales.receipt',$quote))->assertNotFound();
        $this->get(route('sales.show',$quote))->assertOk()
            ->assertDontSee('Comprovante de venda');

        $normal=$this->sale($user,'sale','manual');
        $this->get(route('pdv.receipt',$normal))->assertForbidden();
        $this->get(route('sales.receipt',$normal))->assertOk();
    }

    public function test_receipt_requires_authentication_and_sales_permission(): void
    {
        $seller=$this->operator(['sales']);
        $sale=$this->sale($seller);
        auth()->logout();
        $this->get(route('sales.receipt',$sale))->assertRedirect('/login');

        $pdvOnly=$this->operator(['pdv']);
        $this->actingAs($pdvOnly)
            ->get(route('sales.receipt',$sale))->assertForbidden();
        $this->get(route('pdv.receipt',$sale))->assertNotFound();
    }

    public function test_pdv_receipt_preserves_cashier_flow_and_its_existing_print_template(): void
    {
        $cashier=$this->operator(['pdv']);
        $sale=$this->sale($cashier,'sale','pdv');
        $this->actingAs($cashier)
            ->get(route('pdv.receipt',['sale'=>$sale,'print'=>0]))
            ->assertOk()
            ->assertViewIs('pdv.receipt')
            ->assertSee('Voltar ao PDV')
            ->assertSee('NFC-e ainda não preparada')
            ->assertSee('SEM VALOR FISCAL')
            ->assertDontSee('Voltar à venda');

        $this->get(route('sales.receipt',$sale))->assertForbidden();
    }

    public function test_sales_receipt_does_not_use_pdv_cash_amount_from_previous_session(): void
    {
        $seller=$this->operator(['sales']);
        $sale=$this->sale($seller);
        $sale->update(['cash_received'=>'65.00','change_amount'=>'0.00']);
        PaymentMethod::firstOrCreate(['code'=>'cash'],[
            'name'=>'Dinheiro','kind'=>'cash','is_active'=>true,'pdv_enabled'=>true,
        ]);
        $sale->payments()->create([
            'installment'=>1,'amount'=>'65.00','payment_method'=>'cash',
            'receivable'=>false,
        ]);

        $this->actingAs($seller)->withSession([
            'pdv_cash_received'=>1000,
            'pdv_change'=>935,
        ])->get(route('sales.receipt',$sale))
            ->assertOk()
            ->assertSee('65,00')
            ->assertDontSee('1.000,00')
            ->assertDontSee('935,00');
    }
}
