<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\SaleReturn;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\SaleReturnService;
use App\Services\SalesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SaleReturnTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create([
            'name'=>'Operador',
            'email'=>'returns@example.com',
            'password'=>'senhaSegura123',
        ]);
    }

    private function product(): Product
    {
        return Product::create([
            'sku'=>'RET001',
            'name'=>'Produto devolução',
            'unit'=>'UN',
            'cost_price'=>'10.00',
            'sale_price'=>'30.00',
            'minimum_stock'=>'0.000',
            'stock_quantity'=>'10.000',
            'control_stock'=>true,
            'is_active'=>true,
        ]);
    }

    private function sale(User $user, Product $product)
    {
        return app(SalesService::class)->create([
            'operation_type'=>'sale',
            'operation_date'=>'2026-10-02',
            'items'=>[[
                'item_type'=>'product',
                'product_id'=>$product->id,
                'quantity'=>'3.000',
                'unit_price'=>'30.00',
                'discount'=>'0.01',
            ]],
            'payments'=>[[
                'amount'=>'89.99',
                'due_date'=>'2026-10-10',
                'payment_method'=>'bank_slip',
                'receivable'=>true,
            ]],
        ],$user->id);
    }

    public function test_partial_return_restores_only_returned_stock(): void
    {
        $user=$this->user();
        $product=$this->product();
        $sale=$this->sale($user,$product);
        $item=$sale->items()->firstOrFail();

        $this->assertSame('7.000',$product->fresh()->stock_quantity);

        $return=app(SaleReturnService::class)->create([
            'sale_id'=>$sale->id,
            'return_date'=>'2026-10-03',
            'items'=>[[
                'sale_item_id'=>$item->id,
                'quantity'=>'1.000',
                'reason'=>'Cliente desistiu de uma unidade',
            ]],
        ],$user->id);

        $this->assertSame('8.000',$product->fresh()->stock_quantity);
        $this->assertSame('30.00',$return->total);
        $this->assertDatabaseHas('sale_return_items',[
            'sale_return_id'=>$return->id,
            'sale_item_id'=>$item->id,
            'quantity'=>'1.000',
        ]);
        $this->assertTrue(StockMovement::query()->where('type','sale_return')->where('sale_id',$sale->id)->exists());
    }

    public function test_multiple_partial_returns_cannot_exceed_sold_quantity_and_close_rounding(): void
    {
        $user=$this->user();
        $product=$this->product();
        $sale=$this->sale($user,$product);
        $item=$sale->items()->firstOrFail();
        $service=app(SaleReturnService::class);

        $first=$service->create([
            'sale_id'=>$sale->id,
            'return_date'=>'2026-10-03',
            'items'=>[['sale_item_id'=>$item->id,'quantity'=>'1.000']],
        ],$user->id);

        $second=$service->create([
            'sale_id'=>$sale->id,
            'return_date'=>'2026-10-04',
            'items'=>[['sale_item_id'=>$item->id,'quantity'=>'2.000']],
        ],$user->id);

        $this->assertSame('30.00',$first->total);
        $this->assertSame('59.99',$second->total);
        $this->assertSame('89.99',number_format((float)$first->total+(float)$second->total,2,'.',''));
        $this->assertSame('10.000',$product->fresh()->stock_quantity);

        $this->expectException(ValidationException::class);
        $service->create([
            'sale_id'=>$sale->id,
            'return_date'=>'2026-10-05',
            'items'=>[['sale_item_id'=>$item->id,'quantity'=>'0.001']],
        ],$user->id);
    }

    public function test_sale_cannot_be_cancelled_while_active_return_exists(): void
    {
        $user=$this->user();
        $product=$this->product();
        $sales=app(SalesService::class);
        $sale=$this->sale($user,$product);
        $item=$sale->items()->firstOrFail();

        app(SaleReturnService::class)->create([
            'sale_id'=>$sale->id,
            'return_date'=>'2026-10-03',
            'items'=>[['sale_item_id'=>$item->id,'quantity'=>'1.000']],
        ],$user->id);

        $this->expectException(ValidationException::class);
        $sales->cancel($sale,$user->id);
    }

    public function test_return_cancellation_removes_returned_stock_again(): void
    {
        $user=$this->user();
        $product=$this->product();
        $sale=$this->sale($user,$product);
        $item=$sale->items()->firstOrFail();
        $service=app(SaleReturnService::class);

        $return=$service->create([
            'sale_id'=>$sale->id,
            'return_date'=>'2026-10-03',
            'items'=>[['sale_item_id'=>$item->id,'quantity'=>'1.000']],
        ],$user->id);

        $service->cancel($return,$user->id);

        $this->assertSame('7.000',$product->fresh()->stock_quantity);
        $this->assertSame('cancelled',SaleReturn::query()->findOrFail($return->id)->status);
        $this->assertTrue(StockMovement::query()->where('type','sale_return_cancel')->where('sale_id',$sale->id)->exists());
    }

    public function test_return_pages_require_authentication(): void
    {
        $this->get('/sales/returns')->assertRedirect('/login');
        $this->get('/sales/returns/create')->assertRedirect('/login');
    }
}
