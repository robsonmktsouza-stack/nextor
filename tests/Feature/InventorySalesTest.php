<?php
namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventorySalesTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create(['name' => 'Administrador','email'=>'admin@example.com','password'=>'senhaSegura123']);
    }
    private function product(): Product
    {
        return Product::create([
            'sku'=>'PR001','name'=>'Produto de Teste','unit'=>'UN','cost_price'=>'10.00',
            'sale_price'=>'19.90','minimum_stock'=>'2.000','stock_quantity'=>'0.000','is_active'=>true,
        ]);
    }
    public function test_entry_and_sale_create_auditable_movements(): void
    {
        $p=$this->product();$u=$this->user();
        $this->actingAs($u)->post('/stock',['product_id'=>$p->id,'type'=>'entry','quantity'=>'5.000','reason'=>'Compra'])->assertRedirect('/stock');
        $this->assertEquals('5.000',$p->fresh()->stock_quantity);
        $response=$this->actingAs($u)->post('/sales',['items'=>[['product_id'=>$p->id,'quantity'=>'2.000']]]);
        $response->assertRedirect();
        $this->assertEquals('3.000',$p->fresh()->stock_quantity);
        $this->assertEquals('39.80',Sale::firstOrFail()->total);
        $this->assertSame(2,StockMovement::count());
    }
    public function test_sale_fails_atomically_when_stock_is_insufficient(): void
    {
        $p=$this->product();$u=$this->user();
        $this->actingAs($u)->post('/sales',['items'=>[['product_id'=>$p->id,'quantity'=>'1.000']]])->assertSessionHasErrors('items');
        $this->assertEquals('0.000',$p->fresh()->stock_quantity);
        $this->assertSame(0,Sale::count());
        $this->assertSame(0,StockMovement::count());
    }
    public function test_cancellation_restores_stock_and_cannot_repeat(): void
    {
        $p=$this->product();$u=$this->user();
        $this->actingAs($u)->post('/stock',['product_id'=>$p->id,'type'=>'entry','quantity'=>'3.000','reason'=>'Abertura']);
        $this->actingAs($u)->post('/sales',['items'=>[['product_id'=>$p->id,'quantity'=>'2.000']]]);
        $sale=Sale::firstOrFail();
        $this->actingAs($u)->post("/sales/{$sale->id}/cancel")->assertRedirect();
        $this->assertEquals('3.000',$p->fresh()->stock_quantity);
        $this->assertSame('cancelled',$sale->fresh()->status);
        $this->actingAs($u)->post("/sales/{$sale->id}/cancel")->assertSessionHasErrors('sale');
        $this->assertEquals('3.000',$p->fresh()->stock_quantity);
        $this->assertSame(3,StockMovement::count());
    }
    public function test_guest_cannot_access_business_pages(): void
    {
        $this->get('/products')->assertRedirect('/login');
        $this->get('/sales')->assertRedirect('/login');
    }
}
