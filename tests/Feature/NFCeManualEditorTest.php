<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\CompanySetting;
use App\Models\FiscalDocumentJob;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\FiscalPreparationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class NFCeManualEditorTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::query()->create([
            'name'=>'Operador Fiscal','email'=>'nfce-manual@example.com',
            'password'=>'senhaSegura123','role'=>'admin','is_active'=>true,
        ]);
    }

    private function configure(): Product
    {
        AppSetting::put('fiscal','enabled',true);
        AppSetting::put('nfce','enabled',true);
        AppSetting::put('nfce','environment','homologation');
        AppSetting::put('nfce','series',1);
        AppSetting::put('nfce','next_number',5);
        AppSetting::put('operations','auto_finance_sale',true);
        CompanySetting::query()->create(['state'=>'BA','crt'=>'1']);

        PaymentMethod::query()->create([
            'code'=>'cash','name'=>'Dinheiro','kind'=>'cash',
            'is_active'=>true,'pdv_enabled'=>true,'sort_order'=>1,
        ]);
        return Product::query()->create([
            'sku'=>'EXAMPLE-01','name'=>'Refrigerante teste','ean_gtin'=>'7891234567895',
            'ncm'=>'22021000','unit'=>'UN','sale_price'=>'10.00',
            'stock_quantity'=>'10.000','control_stock'=>true,
            'is_active'=>true,'origin'=>'0',
            'tax_defaults'=>[
                'cfop_outbound_internal'=>'5102','icms_csosn'=>'102',
                'pis_cst'=>'49','cofins_cst'=>'49',
            ],
        ]);
    }

    private function postData(Product $product): array
    {
        return [
            'mode'=>'new','submit_mode'=>'save',
            'presence'=>'presential',
            'items'=>[[
                'product_id'=>$product->id,
                'quantity'=>'2',
                'unit_price'=>'10.00',
                'discount'=>'0.00',
            ]],
            'payments'=>[[
                'payment_method'=>'cash','amount'=>'20.00',
            ]],
        ];
    }

    public function test_fiscal_new_action_and_five_tab_editor_are_available(): void
    {
        $this->configure();
        $this->actingAs($this->admin());

        $this->get(route('fiscal.index',['tab'=>'nfce']))
            ->assertOk()
            ->assertSee(route('fiscal.nfce.create'),false)
            ->assertSee('Nova');

        $this->get(route('fiscal.nfce.create'))
            ->assertOk()
            ->assertSee('Nova NFC-e')
            ->assertSee('Dados gerais')
            ->assertSee('Consumidor')
            ->assertSee('Produtos')
            ->assertSee('Pagamento')
            ->assertSee('Resumo')
            ->assertSee('7891234567895');
    }

    public function test_manual_new_nfce_uses_same_sale_stock_finance_and_fiscal_records_as_pdv(): void
    {
        $product=$this->configure();
        $this->actingAs($this->admin());
        Queue::fake();

        $this->post(route('fiscal.nfce.store'),$this->postData($product))
            ->assertRedirect();

        $sale=Sale::query()->sole();
        self::assertSame('nfce',$sale->source);
        self::assertSame('completed',$sale->status);
        self::assertSame('20.00',(string)$sale->total);
        self::assertSame('8.000',(string)$product->fresh()->stock_quantity);
        self::assertCount(1,$sale->items);
        self::assertCount(1,$sale->payments);

        $doc=FiscalDocumentJob::query()->where('sale_id',$sale->id)
            ->where('document_type','nfce')->sole();
        self::assertSame('prepared',$doc->status);
        self::assertSame(5,(int)$doc->document_number);
        self::assertSame('nfce',$doc->source_snapshot['source']);
        self::assertSame('20.00',$doc->source_snapshot['total']);
        self::assertCount(1,$doc->source_snapshot['items']);
        self::assertCount(1,$doc->source_snapshot['payments']);
        Queue::assertNothingPushed();

        $same=app(FiscalPreparationService::class)->prepareNfceForSale($sale);
        self::assertSame($doc->id,$same->id);
        self::assertSame('8.000',(string)$product->fresh()->stock_quantity);
        self::assertSame(1,FiscalDocumentJob::query()->count());
        self::assertSame(6,(int)AppSetting::value('nfce','next_number'));
    }

    public function test_existing_sale_can_be_linked_once_without_repeating_stock_or_finance(): void
    {
        $product=$this->configure();
        $sale=Sale::query()->create([
            'operation_type'=>'sale','source'=>'manual','status'=>'completed',
            'operation_date'=>now(),'total'=>'10.00',
        ]);
        $sale->items()->create([
            'item_type'=>'product','product_id'=>$product->id,
            'product_name'=>$product->name,'product_sku'=>$product->sku,
            'quantity'=>'1.000','unit_price'=>'10.00',
            'discount'=>'0.00','line_total'=>'10.00',
        ]);
        $sale->payments()->create([
            'installment'=>1,'amount'=>'10.00','payment_method'=>'cash',
        ]);

        $this->actingAs($this->admin());
        foreach ([1,2] as $attempt) {
            $this->post(route('fiscal.nfce.store'),[
                'mode'=>'existing','submit_mode'=>'save','sale_id'=>$sale->id,
            ])->assertRedirect();
        }

        self::assertSame('10.000',(string)$product->fresh()->stock_quantity);
        self::assertSame(1,Sale::query()->count());
        self::assertSame(1,FiscalDocumentJob::query()->count());
        self::assertSame(6,(int)AppSetting::value('nfce','next_number'));
    }

    public function test_invoice_is_not_prepared_if_sale_has_another_fiscal_document(): void
    {
        $product=$this->configure();
        $sale=Sale::query()->create([
            'operation_type'=>'sale','source'=>'manual','status'=>'completed',
            'operation_date'=>now(),'total'=>'10.00',
        ]);
        $sale->items()->create([
            'item_type'=>'product','product_id'=>$product->id,
            'product_name'=>$product->name,'product_sku'=>$product->sku,
            'quantity'=>'1.000','unit_price'=>'10.00','discount'=>'0.00','line_total'=>'10.00',
        ]);
        $sale->payments()->create([
            'installment'=>1,'amount'=>'10.00','payment_method'=>'cash',
        ]);
        FiscalDocumentJob::query()->create([
            'document_type'=>'nfe','sale_id'=>$sale->id,'status'=>'prepared',
            'environment'=>'homologation','document_number'=>1,'series'=>1,
        ]);

        $this->actingAs($this->admin())
            ->post(route('fiscal.nfce.store'),[
                'mode'=>'existing','submit_mode'=>'save','sale_id'=>$sale->id,
            ])
            ->assertSessionHasErrors('sale_id');

        self::assertSame(0,FiscalDocumentJob::query()->where('document_type','nfce')->count());
    }

    public function test_invalid_payment_cannot_create_sale_or_move_stock(): void
    {
        $product=$this->configure();
        $payload=$this->postData($product);
        $payload['payments'][0]['amount']='15.00';

        $this->actingAs($this->admin())
            ->post(route('fiscal.nfce.store'),$payload)
            ->assertSessionHasErrors('payments');

        self::assertSame(0,Sale::query()->count());
        self::assertSame('10.000',(string)$product->fresh()->stock_quantity);
    }

    public function test_disabling_nfce_prevents_sale_creation_from_fiscal_editor(): void
    {
        $product=$this->configure();
        AppSetting::put('nfce','enabled',false);

        $this->actingAs($this->admin())
            ->post(route('fiscal.nfce.store'),$this->postData($product))
            ->assertSessionHasErrors('mode');

        self::assertSame(0,Sale::query()->count());
    }

    public function test_fiscal_only_user_cannot_create_commercial_sale(): void
    {
        $product=$this->configure();
        $user=User::query()->create([
            'name'=>'Fiscal','email'=>'fiscal-limited@example.com',
            'password'=>'senhaSegura123','role'=>'manager',
            'permissions'=>['fiscal'],'is_active'=>true,
        ]);

        $this->actingAs($user)
            ->post(route('fiscal.nfce.store'),$this->postData($product))
            ->assertForbidden();

        self::assertSame(0,Sale::query()->count());
    }
}
