<?php

namespace Tests\Feature;

use App\Models\FinancialAccount;
use App\Models\FinancialEntry;
use App\Models\FinancialSettlement;
use App\Models\Product;
use App\Models\User;
use App\Services\FinancialService;
use App\Services\SalesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialModuleTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create([
            'name'=>'Administrador',
            'email'=>'finance@example.com',
            'password'=>'senhaSegura123',
        ]);
    }

    private function product(): Product
    {
        return Product::create([
            'sku'=>'FIN001',
            'name'=>'Produto Financeiro',
            'unit'=>'UN',
            'cost_price'=>'5.00',
            'sale_price'=>'20.00',
            'minimum_stock'=>'0.000',
            'stock_quantity'=>'10.000',
            'control_stock'=>true,
            'is_active'=>true,
        ]);
    }

    public function test_sale_on_credit_creates_open_receivable(): void
    {
        $user=$this->user();
        $product=$this->product();

        app(SalesService::class)->create([
            'operation_type'=>'sale',
            'operation_date'=>'2026-10-02',
            'items'=>[[
                'item_type'=>'product',
                'product_id'=>$product->id,
                'quantity'=>'1.000',
                'unit_price'=>'20.00',
                'discount'=>'0.00',
            ]],
            'payments'=>[[
                'amount'=>'20.00',
                'due_date'=>'2026-10-15',
                'payment_method'=>'bank_slip',
                'receivable'=>true,
            ]],
        ],$user->id);

        $entry=FinancialEntry::query()->sole();

        $this->assertSame('receivable',$entry->type);
        $this->assertSame('open',$entry->status);
        $this->assertSame('20.00',$entry->amount);
        $this->assertSame('0.00',$entry->paid_amount);
        $this->assertSame(0,FinancialSettlement::query()->count());
    }

    public function test_cash_sale_is_registered_as_paid_with_settlement(): void
    {
        $user=$this->user();
        $product=$this->product();

        app(SalesService::class)->create([
            'operation_type'=>'sale',
            'operation_date'=>'2026-10-02',
            'items'=>[[
                'item_type'=>'product',
                'product_id'=>$product->id,
                'quantity'=>'1.000',
                'unit_price'=>'20.00',
                'discount'=>'0.00',
            ]],
            'payments'=>[[
                'amount'=>'20.00',
                'due_date'=>'2026-10-02',
                'payment_method'=>'cash',
                'receivable'=>false,
            ]],
        ],$user->id);

        $entry=FinancialEntry::query()->sole();
        $settlement=FinancialSettlement::query()->sole();

        $this->assertSame('paid',$entry->status);
        $this->assertSame('20.00',$entry->paid_amount);
        $this->assertSame('20.00',$settlement->amount);
        $this->assertNotNull($settlement->financial_account_id);
    }

    public function test_partial_and_full_settlement_recalculate_entry(): void
    {
        $user=$this->user();
        $account=FinancialAccount::query()->firstOrFail();
        $entry=FinancialEntry::query()->create([
            'type'=>'payable',
            'status'=>'open',
            'description'=>'Despesa teste',
            'issue_date'=>'2026-10-02',
            'due_date'=>'2026-10-10',
            'amount'=>'100.00',
            'paid_amount'=>'0.00',
            'created_by'=>$user->id,
        ]);

        $service=app(FinancialService::class);

        $service->settle($entry,[
            'amount'=>'40.00',
            'settled_at'=>'2026-10-03',
            'financial_account_id'=>$account->id,
            'payment_method'=>'pix',
        ],$user->id);

        $this->assertSame('partial',$entry->fresh()->status);
        $this->assertSame('40.00',$entry->fresh()->paid_amount);

        $service->settle($entry->fresh(),[
            'amount'=>'60.00',
            'settled_at'=>'2026-10-04',
            'financial_account_id'=>$account->id,
            'payment_method'=>'pix',
        ],$user->id);

        $this->assertSame('paid',$entry->fresh()->status);
        $this->assertSame('100.00',$entry->fresh()->paid_amount);
    }

    public function test_sale_cancellation_reverses_financial_effect(): void
    {
        $user=$this->user();
        $product=$this->product();
        $sales=app(SalesService::class);

        $sale=$sales->create([
            'operation_type'=>'sale',
            'operation_date'=>'2026-10-02',
            'items'=>[[
                'item_type'=>'product',
                'product_id'=>$product->id,
                'quantity'=>'1.000',
                'unit_price'=>'20.00',
                'discount'=>'0.00',
            ]],
            'payments'=>[[
                'amount'=>'20.00',
                'due_date'=>'2026-10-02',
                'payment_method'=>'pix',
                'receivable'=>false,
            ]],
        ],$user->id);

        $sales->cancel($sale,$user->id);

        $entry=FinancialEntry::query()->sole();
        $settlement=FinancialSettlement::query()->sole();

        $this->assertSame('cancelled',$entry->fresh()->status);
        $this->assertSame('0.00',$entry->fresh()->paid_amount);
        $this->assertNotNull($settlement->fresh()->reversed_at);
    }

    public function test_finance_pages_require_authentication(): void
    {
        $this->get('/finance')->assertRedirect('/login');
        $this->get('/finance/entries')->assertRedirect('/login');
    }
}
