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
        CompanySetting::query()->create(['document'=>'12345678000199','legal_name'=>'Emitente de Teste LTDA','state'=>'BA','city'=>'Salvador','crt'=>'1']);

        PaymentMethod::query()->updateOrCreate(['code'=>'cash'],[
            'name'=>'Dinheiro','kind'=>'cash',
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

    public function test_tabs_hide_inactive_panels_and_use_trash_icons_for_removal(): void
    {
        $this->configure();
        $this->actingAs($this->admin());

        $response=$this->get(route('fiscal.nfce.create'))->assertOk()
            ->assertSee('role="tablist"',false)
            ->assertSee('id="nfceTab-general"',false)
            ->assertSee('id="nfcePanel-general"',false);

        foreach (['consumer','products','payment','summary'] as $tab) {
            $response->assertSee('data-nfce-panel="'.$tab.'" hidden',false);
        }

        $script=file_get_contents(public_path('js/nfce-editor.js'));
        self::assertNotFalse($script);
        self::assertStringContainsString('panel.hidden=!active',$script);
        self::assertStringContainsString('panel.classList.toggle(',$script);
        self::assertStringContainsString('data-remove-item',$script);
        self::assertStringContainsString('data-remove-payment',$script);
        self::assertStringContainsString("const trashIcon='<svg",$script);
        self::assertStringNotContainsString('aria-label="Remover produto">×</button>',$script);
        self::assertStringNotContainsString('aria-label="Remover pagamento">×</button>',$script);
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

    public function test_invalid_consumer_digits_do_not_create_sale_or_move_stock(): void
    {
        $product=$this->configure();
        $payload=$this->postData($product);
        $payload['consumer_document']='529.982.247-26'; // tamanho correto, dígito inválido

        $this->actingAs($this->admin())
            ->post(route('fiscal.nfce.store'),$payload)
            ->assertSessionHasErrors('consumer_document');

        self::assertSame(0,Sale::query()->count());
        self::assertSame('10.000',(string)$product->fresh()->stock_quantity);
    }

    public function test_integrated_card_with_incomplete_or_invalid_metadata_does_not_commit_sale(): void
    {
        $product=$this->configure();
        PaymentMethod::query()->updateOrCreate(['code'=>'credit-card'],[
            'name'=>'Cartão de crédito','kind'=>'credit_card',
            'is_active'=>true,'pdv_enabled'=>true,'sort_order'=>2,
        ]);
        $payload=$this->postData($product);
        $payload['payments']=[[
            'payment_method'=>'credit-card','amount'=>'20.00',
            'integration_type'=>'1',
        ]];

        $this->actingAs($this->admin())
            ->post(route('fiscal.nfce.store'),$payload)
            ->assertSessionHasErrors('payments.0.institution_document');

        $payload['payments'][0]['institution_document']='11.222.333/0001-82';
        $payload['payments'][0]['authorization_code']='AUT-0001';
        $this->post(route('fiscal.nfce.store'),$payload)
            ->assertSessionHasErrors('payments.0.institution_document');

        self::assertSame(0,Sale::query()->count());
        self::assertSame('10.000',(string)$product->fresh()->stock_quantity);
    }

    public function test_valid_manual_card_payment_metadata_reaches_sale_and_fiscal_snapshot(): void
    {
        $product=$this->configure();
        PaymentMethod::query()->updateOrCreate(['code'=>'credit-card'],[
            'name'=>'Cartão de crédito','kind'=>'credit_card',
            'is_active'=>true,'pdv_enabled'=>true,'sort_order'=>2,
        ]);
        $payload=$this->postData($product);
        $payload['consumer_document']='529.982.247-25';
        $payload['consumer_name']='Consumidor teste';
        $payload['payments']=[[
            'payment_method'=>'credit-card','amount'=>'20.00',
            'integration_type'=>'1',
            'institution_document'=>'11.222.333/0001-81',
            'transaction_document'=>'11.222.333/0001-81',
            'transaction_state'=>'BA',
            'beneficiary_document'=>'11.222.333/0001-81',
            'authorization_code'=>'AUT-1234',
            'card_brand'=>'01',
            'terminal_id'=>'CAIXA-01',
        ]];

        $this->actingAs($this->admin())
            ->post(route('fiscal.nfce.store'),$payload)->assertRedirect();

        $sale=Sale::query()->sole();
        self::assertSame('52998224725',$sale->consumer_document);
        self::assertSame(1,$sale->payments()->count());
        self::assertSame('11222333000181',$sale->payments->first()->institution_document);
        self::assertSame('AUT-1234',$sale->payments->first()->authorization_code);
        self::assertSame('8.000',(string)$product->fresh()->stock_quantity);

        $job=FiscalDocumentJob::query()->where('sale_id',$sale->id)->sole();
        $payment=$job->source_snapshot['payments'][0];
        self::assertSame('1',$payment['integration_type']);
        self::assertSame('11222333000181',$payment['transaction_document']);
        self::assertSame('BA',$payment['transaction_state']);
        self::assertSame('11222333000181',$payment['institution_document']);
        self::assertSame('11222333000181',$payment['beneficiary_document']);
        self::assertSame('AUT-1234',$payment['authorization_code']);
        self::assertSame('CAIXA-01',$payment['terminal_id']);
    }

    public function test_sefaz_payment_fields_and_readonly_guard_are_present_in_editor(): void
    {
        $this->configure();
        $this->actingAs($this->admin())
            ->get(route('fiscal.nfce.create'))->assertOk()
            ->assertDontSee('<span>Modelo fiscal</span>',false)
            ->assertDontSee('<span>Série configurada</span>',false)
            ->assertSee('Homologação')
            ->assertSee('CPF / CNPJ (opcional)')
            ->assertSee('Pagamento')
            ->assertDontSee('nfce-compliance-note',false)
            ->assertDontSee('<h3>Dados gerais</h3>',false)
            ->assertDontSee('não altere esses dados para tentar corrigir uma rejeição');

        $js=file_get_contents(public_path('js/nfce-editor.js'));
        self::assertNotFalse($js);
        self::assertStringContainsString("const readOnly=form.dataset.readonly==='1';",$js);
        foreach ([
            'transaction_document','transaction_state','institution_document',
            'beneficiary_document','terminal_id','authorization_code',
            'card_brand','integration_type',
        ] as $field) {
            self::assertStringContainsString("'".$field."'",$js);
        }
        self::assertStringContainsString('data-payment-detail',$js);
    }



    public function test_pix_metadata_is_saved_without_inventing_a_card_authorization(): void
    {
        $product=$this->configure();
        PaymentMethod::query()->updateOrCreate(['code'=>'pix'],[
            'name'=>'PIX','kind'=>'pix','is_active'=>true,
            'pdv_enabled'=>true,'sort_order'=>3,
        ]);
        $payload=$this->postData($product);
        $payload['payments']=[[
            'payment_method'=>'pix',
            'amount'=>'20.00',
            'transaction_document'=>'11.222.333/0001-81',
            'transaction_state'=>'BA',
            'beneficiary_document'=>'11.222.333/0001-81',
        ]];

        $this->actingAs($this->admin())
            ->post(route('fiscal.nfce.store'),$payload)->assertRedirect();

        $sale=Sale::query()->sole();
        self::assertSame(1,$sale->payments()->count());
        self::assertNull($sale->payments->first()->authorization_code);
        $doc=FiscalDocumentJob::query()->where('sale_id',$sale->id)->sole();
        self::assertSame('pix',$doc->source_snapshot['payments'][0]['payment_kind']);
        self::assertSame('11222333000181',$doc->source_snapshot['payments'][0]['beneficiary_document']);
        self::assertSame('BA',$doc->source_snapshot['payments'][0]['transaction_state']);
    }

    public function test_cash_payment_cannot_carry_card_fields_into_xml_snapshot(): void
    {
        $product=$this->configure();
        $payload=$this->postData($product);
        $payload['payments'][0]['integration_type']='1';
        $payload['payments'][0]['authorization_code']='AUT123';

        $this->actingAs($this->admin())
            ->post(route('fiscal.nfce.store'),$payload)
            ->assertSessionHasErrors('payments.0.payment_method');

        self::assertSame(0,Sale::query()->count());
        self::assertSame('10.000',(string)$product->fresh()->stock_quantity);
    }

    public function test_issue_with_invalid_product_tax_data_does_not_commit_sale_or_stock(): void
    {
        $product=$this->configure();
        $product->update(['ncm'=>null]);
        $payload=$this->postData($product);
        $payload['submit_mode']='issue';

        $this->actingAs($this->admin())
            ->post(route('fiscal.nfce.store'),$payload)
            ->assertSessionHasErrors('items.0.product_id');

        self::assertSame(0,Sale::query()->count());
        self::assertSame('10.000',(string)$product->fresh()->stock_quantity);
    }


    public function test_seeded_card_methods_are_distinct_and_available_in_nfce(): void
    {
        $this->configure();
        self::assertSame('credit_card',PaymentMethod::where('code','credit_card')->value('kind'));
        self::assertSame('debit_card',PaymentMethod::where('code','debit_card')->value('kind'));

        $this->actingAs($this->admin())
            ->get(route('fiscal.nfce.create'))
            ->assertOk()
            ->assertSee('"kind":"credit_card"',false)
            ->assertSee('"kind":"debit_card"',false);
    }

    public function test_credit_and_debit_are_saved_as_distinct_fiscal_payments(): void
    {
        $product=$this->configure();
        $this->actingAs($this->admin());
        foreach (['credit_card','debit_card'] as $kind) {
            $payload=$this->postData($product);
            $payload['payments']=[[
                'payment_method'=>$kind,'amount'=>'20.00','integration_type'=>'2',
                'card_brand'=>$kind==='credit_card' ? '27' : '01',
            ]];
            $this->post(route('fiscal.nfce.store'),$payload)->assertSessionHasNoErrors();
        }

        self::assertSame(2,Sale::query()->count());
        self::assertSame(2,FiscalDocumentJob::query()->where('document_type','nfce')->count());
        $snapshots=FiscalDocumentJob::query()->orderBy('id')->get()
            ->map(fn($job)=>$job->source_snapshot['payments'][0])->all();
        self::assertSame('credit_card',$snapshots[0]['payment_kind']);
        self::assertSame('debit_card',$snapshots[1]['payment_kind']);
        self::assertSame('2',$snapshots[0]['integration_type']);
        self::assertSame('2',$snapshots[1]['integration_type']);
    }

    public function test_mixed_cash_pix_and_debit_are_saved_as_one_sale(): void
    {
        $product=$this->configure();
        $this->actingAs($this->admin());
        $payload=$this->postData($product);
        $payload['payments']=[
            ['payment_method'=>'cash','amount'=>'5.00'],
            ['payment_method'=>'pix','amount'=>'5.00'],
            ['payment_method'=>'debit_card','amount'=>'10.00','integration_type'=>'2'],
        ];

        $this->post(route('fiscal.nfce.store'),$payload)->assertSessionHasNoErrors();
        self::assertSame(1,Sale::query()->count());
        $sale=Sale::query()->firstOrFail();
        self::assertSame(3,$sale->payments()->count());
        $job=FiscalDocumentJob::query()->firstOrFail();
        self::assertSame(['cash','pix','debit_card'],
            array_column($job->source_snapshot['payments'],'payment_kind'));
        self::assertSame('20.00',(string)$job->source_snapshot['total']);
    }

    public function test_pix_cannot_carry_card_brand_into_the_fiscal_sale(): void
    {
        $product=$this->configure();
        $this->actingAs($this->admin());
        $payload=$this->postData($product);
        $payload['payments']=[[
            'payment_method'=>'pix','amount'=>'20.00','card_brand'=>'01',
        ]];
        $this->post(route('fiscal.nfce.store'),$payload)
            ->assertSessionHasErrors('payments.0.card_brand');
        self::assertSame(0,Sale::query()->count());
    }

    public function test_ambiguous_generic_card_cannot_create_a_manual_nfce_sale(): void
    {
        $product=$this->configure();
        PaymentMethod::query()->create([
            'code'=>'card-custom','name'=>'Cartão sem definição',
            'kind'=>'card','is_active'=>true,'pdv_enabled'=>true,
        ]);
        $this->actingAs($this->admin());
        $payload=$this->postData($product);
        $payload['payments']=[[
            'payment_method'=>'card-custom','amount'=>'20.00','integration_type'=>'2',
        ]];

        $this->post(route('fiscal.nfce.store'),$payload)
            ->assertSessionHasErrors('payments.0.payment_method');
        self::assertSame(0,Sale::query()->count());
        self::assertSame(0,FiscalDocumentJob::query()->count());
    }

}
