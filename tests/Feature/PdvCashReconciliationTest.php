<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\PdvCashMovement;
use App\Models\PdvCashSession;
use App\Models\Product;
use App\Models\User;
use App\Services\PdvCashService;
use App\Services\SaleReturnService;
use App\Services\SalesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PdvCashReconciliationTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\WithConfiguredCompany;

    private function cashier(): User
    {
        return User::firstOrCreate(['email'=>'cashier-tests@example.test'],[
            'name'=>'Operador PDV','role'=>'admin','password'=>'senha123segura','is_active'=>true,
        ]);
    }

    private function product(): Product
    {
        return Product::firstOrCreate(['sku'=>'CASH-001'],[
            'name'=>'Produto PDV','unit'=>'UN','sale_price'=>'50.00','cost_price'=>'12.00',
            'stock_quantity'=>'10.000','control_stock'=>true,'is_active'=>true,
        ]);
    }

    private function sale(User $user, array $payments): \App\Models\Sale
    {
        return app(SalesService::class)->create([
            'source'=>'pdv','operation_type'=>'sale',
            'operation_date'=>now()->toDateString(),
            'cash_received'=>'100.00','change_amount'=>'40.00',
            'items'=>[['item_type'=>'product','product_id'=>$this->product()->id,
                'quantity'=>'2','unit_price'=>'50.00','discount'=>'0']],
            'payments'=>$payments,
        ],$user->id);
    }

    private function mixedPayments(): array
    {
        return [
            ['amount'=>'60.00','payment_method'=>'cash','receivable'=>false],
            ['amount'=>'40.00','payment_method'=>'pix','receivable'=>false],
        ];
    }

    public function test_physical_cash_counts_net_allocated_amount_and_cannot_double_subtract_change(): void
    {
        AppSetting::put('pdv','require_cash_opening',true);
        $user=$this->cashier();
        $cash=app(PdvCashService::class);
        $session=$cash->open($user->id,'20.00','Fundo inicial');
        $sale=$this->sale($user,$this->mixedPayments());

        self::assertSame($session->id,$sale->pdv_cash_session_id);
        self::assertSame(8000,$cash->breakdown($session)['expected']);
        self::assertSame(6000,$cash->breakdown($session)['cash_sales']);
        self::assertSame(1,$cash->breakdown($session)['sales_count']);
        self::assertSame(6000,$cash->breakdown($session)['payments']['cash']);
        self::assertSame(4000,$cash->breakdown($session)['payments']['pix']);

        $cash->movement($user->id,'supply','15.00','Troco adicional');
        $cash->movement($user->id,'withdrawal','5.00','Cofre');
        self::assertSame(9000,$cash->breakdown($session)['expected']);

        try {
            $cash->close($user->id,'89.00','');
            self::fail('Deveria exigir justificativa da falta.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('closing_notes',$e->errors());
        }
        self::assertNull($session->fresh()->closed_at);

        $closed=$cash->close($user->id,'89.00','Faltou R$ 1,00 na contagem');
        self::assertSame('90.00',$closed->expected_amount);
        self::assertSame('89.00',$closed->closing_amount);
        self::assertSame('-1.00',$closed->difference_amount);
        self::assertSame(9000,$closed->closing_breakdown['expected']);
        self::assertNotNull($closed->closed_by);

        $this->actingAs($user)->get(route('pdv.cash.report',$closed))
            ->assertOk()->assertSee('Fechamento de caixa')
            ->assertSee('Diferença (sobra + / falta');
        $this->post(route('pdv.cash.review',$closed),['review_notes'=>'Conferido com o operador e registrado'])
            ->assertRedirect();
        self::assertNotNull($closed->fresh()->reviewed_at);

        // An existing closed snapshot never changes even after sales are cancelled.
        app(SalesService::class)->cancel($sale,$user->id);
        self::assertSame('90.00',$closed->fresh()->expected_amount);
        self::assertSame('90.00',PdvCashService::currency($cash->breakdown($closed)['expected']));
    }

    public function test_shift_opening_and_closing_are_single_use_and_sale_requires_open_shift(): void
    {
        AppSetting::put('pdv','require_cash_opening',true);
        $user=$this->cashier();
        $cash=app(PdvCashService::class);

        $this->expectException(ValidationException::class);
        $this->sale($user,$this->mixedPayments());
    }

    public function test_duplicate_open_and_double_close_are_rejected(): void
    {
        $user=$this->cashier();
        $cash=app(PdvCashService::class);
        $cash->open($user->id,'0.00',null);
        try {
            $cash->open($user->id,'20.00',null);
            self::fail('Dupla abertura permitida.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('opening_amount',$e->errors());
        }
        $closed=$cash->close($user->id,'0.00',null);
        self::assertNotNull($closed->closed_at);
        try {
            $cash->close($user->id,'0.00',null);
            self::fail('Fechamento duplicado permitido.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('closing_amount',$e->errors());
        }
        self::assertSame(1,PdvCashSession::count());
    }

    public function test_refund_has_a_real_source_and_cannot_exceed_original_cash(): void
    {
        AppSetting::put('pdv','require_cash_opening',true);
        $user=$this->cashier();
        $cash=app(PdvCashService::class);
        $session=$cash->open($user->id,'0.00',null);
        $sale=$this->sale($user,$this->mixedPayments());
        $return=app(SaleReturnService::class)->create([
            'sale_id'=>$sale->id,'return_date'=>now()->toDateString(),
            'items'=>[['sale_item_id'=>$sale->items()->firstOrFail()->id,'quantity'=>'1.000']],
        ],$user->id);
        self::assertSame('50.00',$return->total);

        $cash->movement($user->id,'refund','30.00','Dinheiro entregue ao cliente',null,$return->id);
        self::assertSame(3000,$cash->breakdown($session)['expected']);
        self::assertDatabaseHas('pdv_cash_movements',[
            'type'=>'refund','sale_id'=>$sale->id,'sale_return_id'=>$return->id,'amount'=>'30.00',
        ]);

        try {
            $cash->movement($user->id,'refund','31.00','Reembolso repetido',null,$return->id);
            self::fail('Permitiu reembolsar além do dinheiro da venda.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('amount',$e->errors());
        }
        try {
            app(SaleReturnService::class)->cancel($return,$user->id);
            self::fail('Permitiu cancelar devolução com dinheiro já reembolsado.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('return',$e->errors());
        }
        self::assertSame(1,PdvCashMovement::where('type','refund')->count());
    }

    public function test_operator_cannot_review_or_view_other_operators_shifts(): void
    {
        $admin=$this->cashier();
        $cash=app(PdvCashService::class);
        $closed=$cash->open($admin->id,'4.00',null);
        $cash->close($admin->id,'4.00',null);

        $other=User::create([
            'name'=>'Operador 2','email'=>'other-cash@example.test',
            'password'=>'senha123segura','role'=>'operator','permissions'=>['pdv'],'is_active'=>true,
        ]);
        $this->actingAs($other)->get(route('pdv.cash.report',$closed))->assertForbidden();
        $this->post(route('pdv.cash.review',$closed),['review_notes'=>'Tentar aprovar'])
            ->assertForbidden();
        $this->get(route('pdv.cash.history'))->assertOk()->assertDontSee('Caixa #'.$closed->id);
    }

    public function test_cash_count_input_has_no_prepopulated_expected_value(): void
    {
        AppSetting::put('pdv','require_cash_opening',true);
        $admin=$this->cashier();
        app(PdvCashService::class)->open($admin->id,'19.00',null);
        $this->actingAs($admin)->get(route('pdv.index'))->assertOk()
            ->assertSee('name="closing_amount" value=""',false)
            ->assertSee('Conte o dinheiro físico')
            ->assertSee('Histórico e relatórios de caixa');
    }
}
