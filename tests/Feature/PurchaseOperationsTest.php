<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\FinancialAccount;
use App\Models\FinancialEntry;
use App\Models\FinancialSettlement;
use App\Models\IncomingNfe;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithConfiguredCompany;
use Tests\TestCase;

final class PurchaseOperationsTest extends TestCase
{
    use RefreshDatabase;
    use WithConfiguredCompany;

    private function admin(): User
    {
        return User::query()->create([
            'name'=>'Comprador teste','email'=>'buyer@example.test',
            'password'=>'test-password','role'=>'admin','is_active'=>true,
        ]);
    }
    private function supplier(): Customer
    {
        return Customer::query()->create([
            'name'=>'Distribuidora teste','document'=>'99887766000122',
            'is_supplier'=>true,'is_customer'=>false,
        ]);
    }
    private function product(string $sku='IT-1',bool $control=true): Product
    {
        return Product::query()->create([
            'sku'=>$sku,'name'=>'Produto '.$sku,'unit'=>'UN',
            'cost_price'=>'12.00','sale_price'=>'40.00',
            'stock_quantity'=>'5.000','control_stock'=>$control,
            'is_active'=>true,
        ]);
    }
    private function payload(Customer $supplier,Product $product,array $override=[]): array
    {
        return array_replace([
            'supplier_id'=>$supplier->id,'operation_date'=>today()->toDateString(),
            'due_date'=>today()->addDays(30)->toDateString(),
            'document_number'=>'PED-2026-001',
            'freight'=>'5.00','discount'=>'2.00','generate_payable'=>'1',
            'items'=>[
                ['product_id'=>$product->id,'quantity'=>'2.500','unit_cost'=>'30.00'],
            ],
        ],$override);
    }
    private function draft(Customer $supplier,Product $product,array $override=[]): Purchase
    {
        $this->post(route('purchases.store'),$this->payload($supplier,$product,$override))
            ->assertRedirect()->assertSessionHasNoErrors();
        return Purchase::query()->firstOrFail();
    }

    public function test_purchase_draft_does_not_mutate_stock_and_receipt_generates_single_payable(): void
    {
        $this->actingAs($this->admin());
        $supplier=$this->supplier();$product=$this->product();
        $purchase=$this->draft($supplier,$product);
        self::assertSame('draft',$purchase->status);
        self::assertSame('78.00',$purchase->total);
        self::assertSame('75.00',$purchase->subtotal);
        self::assertSame('5.000',$product->fresh()->stock_quantity);
        $this->assertDatabaseCount('stock_movements',0);
        $this->assertDatabaseCount('financial_entries',0);
        $this->post(route('purchases.receive',$purchase))->assertRedirect()->assertSessionHasNoErrors();
        self::assertSame('received',$purchase->fresh()->status);
        self::assertSame('7.500',$product->fresh()->stock_quantity);
        self::assertSame('30.00',$product->fresh()->cost_price);
        $this->assertDatabaseHas('stock_movements',[
            'product_id'=>$product->id,'purchase_id'=>$purchase->id,'type'=>'purchase',
            'quantity_delta'=>'2.500',
        ]);
        $this->assertDatabaseHas('financial_entries',[
            'purchase_id'=>$purchase->id,'type'=>'payable','status'=>'open',
            'customer_id'=>$supplier->id,'amount'=>'78.00',
        ]);
        $this->assertDatabaseCount('financial_entries',1);
        $this->post(route('purchases.receive',$purchase))->assertSessionHasErrors('purchase');
        $this->assertDatabaseCount('stock_movements',1);
        $this->assertDatabaseCount('financial_entries',1);
    }

    public function test_cancel_reverses_receipt_and_cancels_unpaid_payable_exactly_once(): void
    {
        $this->actingAs($this->admin());
        $purchase=$this->draft($this->supplier(),$product=$this->product());
        $this->post(route('purchases.receive',$purchase))->assertRedirect();
        $this->post(route('purchases.cancel',$purchase))->assertRedirect()->assertSessionHasNoErrors();
        self::assertSame('cancelled',$purchase->fresh()->status);
        self::assertSame('5.000',$product->fresh()->stock_quantity);
        $this->assertDatabaseHas('stock_movements',[
            'purchase_id'=>$purchase->id,'type'=>'purchase_cancel','quantity_delta'=>'-2.500',
        ]);
        self::assertSame('cancelled',$purchase->fresh()->payable->status);
        $this->post(route('purchases.cancel',$purchase))->assertSessionHasErrors('purchase');
        $this->assertDatabaseCount('stock_movements',2);
    }

    public function test_cancel_refuses_paid_invoices_and_already_consumed_stock(): void
    {
        $this->actingAs($this->admin());
        $purchase=$this->draft($this->supplier(),$product=$this->product());
        $this->post(route('purchases.receive',$purchase))->assertRedirect();
        $payable=$purchase->fresh()->payable;
        $account=FinancialAccount::query()->firstOrFail();
        FinancialSettlement::query()->create([
            'financial_entry_id'=>$payable->id,
            'financial_account_id'=>$account->id,'amount'=>'10.00',
            'settled_at'=>today()->toDateString(),'payment_method'=>'pix',
        ]);
        $this->post(route('purchases.cancel',$purchase))->assertSessionHasErrors('purchase');
        self::assertSame('received',$purchase->fresh()->status);
        self::assertSame('7.500',$product->fresh()->stock_quantity);
        $payable->settlements()->delete();
        app(InventoryService::class)->adjust($product->id,'exit','7.000','Saída posterior',(int)auth()->id());
        $this->post(route('purchases.cancel',$purchase))->assertSessionHasErrors('items');
        self::assertSame('received',$purchase->fresh()->status);
        self::assertSame('0.500',$product->fresh()->stock_quantity);
        self::assertSame('open',$purchase->fresh()->payable->status);
    }

    public function test_draft_can_be_edited_without_stock_movement_but_not_after_receipt(): void
    {
        $this->actingAs($this->admin());
        $supplier=$this->supplier();$product=$this->product();
        $purchase=$this->draft($supplier,$product);
        $this->put(route('purchases.update',$purchase),$this->payload($supplier,$product,[
            'freight'=>'0.00','discount'=>'0.00',
            'items'=>[['product_id'=>$product->id,'quantity'=>'3.000','unit_cost'=>'10.00']],
        ]))->assertRedirect()->assertSessionHasNoErrors();
        self::assertSame('30.00',$purchase->fresh()->total);
        self::assertSame('5.000',$product->fresh()->stock_quantity);
        $this->assertDatabaseCount('financial_entries',0);
        $this->post(route('purchases.receive',$purchase))->assertRedirect();
        $this->put(route('purchases.update',$purchase),$this->payload($supplier,$product))
            ->assertSessionHasErrors('purchase');
        self::assertSame('30.00',$purchase->fresh()->total);
    }

    public function test_purchase_rejects_duplicate_products_invalid_supplier_and_duplicate_invoice(): void
    {
        $this->actingAs($this->admin());
        $supplier=$this->supplier();$product=$this->product();
        $payload=$this->payload($supplier,$product);
        $payload['items'][]=$payload['items'][0];
        $this->post(route('purchases.store'),$payload)->assertSessionHasErrors('items');
        $this->assertDatabaseCount('purchases',0);
        $notSupplier=Customer::query()->create(['name'=>'Consumidor','is_supplier'=>false]);
        $this->post(route('purchases.store'),$this->payload($notSupplier,$product))
            ->assertSessionHasErrors('supplier_id');
        $invoice=IncomingNfe::query()->create([
            'access_key'=>'29261099887766000122550010000001231000001234',
            'status'=>'xml_received','xml_path'=>'fiscal/incoming-nfe/test.xml',
            'supplier_cnpj'=>'99887766000122','total'=>'78.00',
        ]);
        $one=$this->draft($supplier,$product,['incoming_nfe_id'=>$invoice->id]);
        $this->post(route('purchases.store'),$this->payload($supplier,$product,[
            'incoming_nfe_id'=>$invoice->id,
        ]))->assertSessionHasErrors('incoming_nfe_id');
        $this->assertDatabaseCount('purchases',1);
        self::assertSame($invoice->id,$one->incoming_nfe_id);
    }

    public function test_can_receive_without_payable_and_untracked_product_stays_unmoved(): void
    {
        $this->actingAs($this->admin());
        $purchase=$this->draft($this->supplier(),$product=$this->product('FREE',false),[
            'generate_payable'=>'0','due_date'=>null,
        ]);
        $this->post(route('purchases.receive',$purchase))->assertRedirect();
        self::assertSame('received',$purchase->fresh()->status);
        self::assertSame('5.000',$product->fresh()->stock_quantity);
        $this->assertDatabaseCount('stock_movements',0);
        $this->assertDatabaseCount('financial_entries',0);
    }

    public function test_permissions_prevent_employee_without_purchases_access(): void
    {
        $user=User::query()->create([
            'name'=>'Operador','email'=>'operator-purchase@example.test',
            'password'=>'password','role'=>'operator','is_active'=>true,
            'permissions'=>['stock','products'],
        ]);
        $this->actingAs($user)->get(route('purchases.index'))->assertForbidden();
        $this->get(route('purchases.create'))->assertForbidden();
        $this->assertDatabaseCount('purchases',0);
    }
}
