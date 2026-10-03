<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\CompanySetting;
use App\Models\FinancialEntry;
use App\Models\FiscalDocumentJob;
use App\Models\PaymentMethod;
use App\Models\PdvCashMovement;
use App\Models\PdvSuspendedSale;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\AccountingExportService;
use App\Services\BillingService;
use App\Services\SalesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OperationalSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create([
            'name'=>'Administrador',
            'email'=>'settings@example.com',
            'password'=>'senhaSegura123',
            'role'=>'admin',
            'is_active'=>true,
        ]);
    }

    private function product(float $stock=10): Product
    {
        return Product::create([
            'sku'=>'SET001',
            'name'=>'Produto Configuração',
            'unit'=>'UN',
            'usage_type'=>'resale',
            'cost_price'=>'5.00',
            'sale_price'=>'100.00',
            'stock_quantity'=>number_format($stock,3,'.',''),
            'minimum_stock'=>'1.000',
            'control_stock'=>true,
            'is_active'=>true,
            'origin'=>'0',
            'ignore_taxes_mode'=>'none',
        ]);
    }

    private function saleData(Product $product,string $operation='sale',string $payment='cash',bool $receivable=false): array
    {
        return [
            'operation_type'=>$operation,
            'operation_date'=>'2026-10-02',
            'items'=>[[
                'item_type'=>'product',
                'product_id'=>$product->id,
                'quantity'=>'1.000',
                'unit_price'=>'100.00',
                'discount'=>'0.00',
            ]],
            'payments'=>$operation==='sale' ? [[
                'amount'=>'100.00',
                'due_date'=>'2026-10-02',
                'payment_method'=>$payment,
                'receivable'=>$receivable,
            ]] : [],
        ];
    }

    public function test_quote_validity_setting_is_applied(): void
    {
        AppSetting::put('operations','quote_valid_days',20);
        $sale=app(SalesService::class)->create(
            $this->saleData($this->product(),'quote'),
            $this->user()->id
        );

        $this->assertSame('2026-10-22',$sale->quote_expires_at->toDateString());
    }

    public function test_negative_stock_setting_is_enforced_by_inventory_engine(): void
    {
        AppSetting::put('inventory','allow_negative_stock',true);
        $user=$this->user();
        $product=$this->product(1);

        $data=$this->saleData($product);
        $data['items'][0]['quantity']='2.000';
        $data['items'][0]['unit_price']='100.00';
        $data['payments'][0]['amount']='200.00';

        app(SalesService::class)->create($data,$user->id);

        $this->assertSame('-1.000',$product->fresh()->stock_quantity);
    }

    public function test_payment_method_fee_generates_financial_expense(): void
    {
        $method=PaymentMethod::query()->where('code','cash')->firstOrFail();
        $method->update(['fee_percent'=>'2.5000','fee_fixed'=>'1.00']);

        app(SalesService::class)->create(
            $this->saleData($this->product()),
            $this->user()->id
        );

        $fee=FinancialEntry::query()->where('source_key','like','payment-fee:%')->firstOrFail();

        $this->assertSame('payable',$fee->type);
        $this->assertSame('3.50',$fee->amount);
        $this->assertSame('paid',$fee->status);
    }

    public function test_enabled_nfe_prepares_fiscal_job_and_reserves_number(): void
    {
        AppSetting::put('fiscal','enabled',true);
        AppSetting::put('nfe','enabled',true);
        AppSetting::put('nfe','auto_from_sale',true);
        AppSetting::put('nfe','environment','homologation');
        AppSetting::put('nfe','series',1);
        AppSetting::put('nfe','next_number',25);

        $sale=app(SalesService::class)->create(
            $this->saleData($this->product()),
            $this->user()->id
        );

        $job=FiscalDocumentJob::query()->where('sale_id',$sale->id)->where('document_type','nfe')->firstOrFail();

        $this->assertSame('prepared',$job->status);
        $this->assertSame(25,$job->document_number);
        $this->assertSame(26,(int)AppSetting::value('nfe','next_number'));
    }

    public function test_pdv_cash_opening_setting_blocks_sale_without_open_session(): void
    {
        AppSetting::put('pdv','require_cash_opening',true);
        $user=$this->user();
        $product=$this->product();

        $response=$this->actingAs($user)->post(route('pdv.store'),[
            'payment_method'=>'cash',
            'cash_received'=>'100.00',
            'items'=>[[
                'item_type'=>'product',
                'product_id'=>$product->id,
                'quantity'=>'1.000',
                'discount'=>'0.00',
            ]],
        ]);

        $response->assertSessionHasErrors('cash_session');
    }

    public function test_pdv_rejects_services_even_if_posted_manually(): void
    {
        $user=$this->user();

        $this->actingAs($user)->post(route('pdv.store'),[
            'payment_method'=>'pix',
            'items'=>[[
                'item_type'=>'service',
                'service_id'=>1,
                'quantity'=>'1.000',
                'discount'=>'0.00',
            ]],
        ])->assertSessionHasErrors('items.0.item_type');

        $this->assertSame(0,Sale::query()->count());
    }

    public function test_pdv_can_store_consumer_document_and_split_payment(): void
    {
        AppSetting::put('pdv','allow_split_payment',true);

        $user=$this->user();
        $product=$this->product();

        $response=$this->actingAs($user)->post(route('pdv.store'),[
            'consumer_document'=>'529.982.247-25',
            'cash_received'=>'50.00',
            'payments'=>[
                ['payment_method'=>'cash','amount'=>'40.00'],
                ['payment_method'=>'pix','amount'=>'60.00'],
            ],
            'items'=>[[
                'item_type'=>'product',
                'product_id'=>$product->id,
                'quantity'=>'1.000',
                'discount'=>'0.00',
            ]],
        ]);

        $response->assertSessionHasNoErrors()->assertRedirect();

        $sale=Sale::query()->firstOrFail();
        $this->assertSame('52998224725',$sale->consumer_document);
        $this->assertSame('50.00',$sale->cash_received);
        $this->assertSame('10.00',$sale->change_amount);
        $this->assertCount(2,$sale->payments);
        $this->assertSame('40.00',$sale->payments->firstWhere('payment_method','cash')->amount);
        $this->assertSame('60.00',$sale->payments->firstWhere('payment_method','pix')->amount);
    }

    public function test_pdv_rejects_invalid_consumer_document(): void
    {
        $user=$this->user();
        $product=$this->product();

        $this->actingAs($user)->post(route('pdv.store'),[
            'consumer_document'=>'111.111.111-11',
            'payment_method'=>'pix',
            'items'=>[[
                'item_type'=>'product',
                'product_id'=>$product->id,
                'quantity'=>'1.000',
                'discount'=>'0.00',
            ]],
        ])->assertSessionHasErrors('consumer_document');

        $this->assertSame(0,Sale::query()->count());
    }

    public function test_pdv_cash_session_records_supply_and_withdrawal(): void
    {
        AppSetting::put('pdv','allow_cash_movements',true);

        $user=$this->user();

        $this->actingAs($user)->post(route('pdv.cash.open'),[
            'opening_amount'=>'100.00',
        ])->assertSessionHasNoErrors();

        $this->actingAs($user)->post(route('pdv.cash.movement'),[
            'type'=>'supply',
            'amount'=>'50.00',
            'reason'=>'Reforço de troco',
        ])->assertSessionHasNoErrors();

        $this->actingAs($user)->post(route('pdv.cash.movement'),[
            'type'=>'withdrawal',
            'amount'=>'30.00',
            'reason'=>'Depósito no cofre',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('pdv_cash_movements',[
            'type'=>'supply','amount'=>'50.00','reason'=>'Reforço de troco',
        ]);
        $this->assertDatabaseHas('pdv_cash_movements',[
            'type'=>'withdrawal','amount'=>'30.00','reason'=>'Depósito no cofre',
        ]);
        $this->assertSame(2,PdvCashMovement::query()->count());
    }

    public function test_pdv_accepts_alphanumeric_cnpj(): void
    {
        $user=$this->user();
        $product=$this->product();

        $this->actingAs($user)->post(route('pdv.store'),[
            'consumer_document'=>'00.000.000/E08G-12',
            'payment_method'=>'pix',
            'items'=>[[
                'item_type'=>'product',
                'product_id'=>$product->id,
                'quantity'=>'1.000',
                'discount'=>'0.00',
            ]],
        ])->assertSessionHasNoErrors();

        $this->assertSame('00000000E08G12',Sale::query()->firstOrFail()->consumer_document);
    }

    public function test_pdv_can_suspend_and_resume_without_moving_stock(): void
    {
        $user=$this->user();
        $product=$this->product(10);

        $response=$this->actingAs($user)->postJson(route('pdv.suspended.store'),[
            'label'=>'Balcão 2',
            'items'=>[[
                'type'=>'product',
                'id'=>$product->id,
                'quantity'=>2,
                'discount'=>0,
            ]],
            'payment_method'=>'pix',
        ])->assertCreated();

        $suspended=PdvSuspendedSale::query()->firstOrFail();
        $this->assertSame('10.000',$product->fresh()->stock_quantity);
        $this->assertSame('200.00',$suspended->total);

        $resume=$this->actingAs($user)
            ->postJson(route('pdv.suspended.resume',$suspended))
            ->assertOk()
            ->json('snapshot');

        $this->assertSame($product->id,$resume['items'][0]['id']);
        $this->assertSame(2.0,(float)$resume['items'][0]['quantity']);
        $this->assertNotNull($suspended->fresh()->resumed_at);
        $this->assertSame('10.000',$product->fresh()->stock_quantity);
    }

    public function test_card_tef_data_is_persisted_and_reaches_nfce_snapshot(): void
    {
        AppSetting::put('fiscal','enabled',true);
        AppSetting::put('nfce','enabled',true);
        AppSetting::put('nfce','auto_from_pdv',true);

        $user=$this->user();
        $product=$this->product();

        $this->actingAs($user)->post(route('pdv.store'),[
            'payments'=>[[
                'payment_method'=>'credit_card',
                'amount'=>'100.00',
                'integration_type'=>'1',
                'institution_document'=>'59.434.778/0001-51',
                'card_brand'=>'02',
                'authorization_code'=>'AUTH12345',
                'beneficiary_document'=>'59.434.778/0001-51',
                'terminal_id'=>'POS-01',
            ]],
            'items'=>[[
                'item_type'=>'product',
                'product_id'=>$product->id,
                'quantity'=>'1.000',
                'discount'=>'0.00',
            ]],
        ])->assertSessionHasNoErrors();

        $sale=Sale::query()->firstOrFail();
        $payment=$sale->payments()->firstOrFail();

        $this->assertSame('1',$payment->integration_type);
        $this->assertSame('59434778000151',$payment->institution_document);
        $this->assertSame('02',$payment->card_brand);
        $this->assertSame('AUTH12345',$payment->authorization_code);
        $this->assertSame('POS-01',$payment->terminal_id);

        $job=FiscalDocumentJob::query()->where('sale_id',$sale->id)->where('document_type','nfce')->firstOrFail();
        $snapshot=$job->source_snapshot;

        $this->assertSame('59434778000151',$snapshot['payments'][0]['institution_document']);
        $this->assertSame('02',$snapshot['payments'][0]['card_brand']);
        $this->assertSame('AUTH12345',$snapshot['payments'][0]['authorization_code']);
    }

    public function test_nfce_contingency_marks_new_jobs_as_offline(): void
    {
        AppSetting::put('fiscal','enabled',true);
        AppSetting::put('nfce','enabled',true);
        AppSetting::put('nfce','auto_from_pdv',true);

        $user=$this->user();

        $this->actingAs($user)->post(route('pdv.nfce.contingency'),[
            'action'=>'start',
            'reason'=>'Indisponibilidade de comunicação com o autorizador da NFC-e.',
        ])->assertSessionHasNoErrors();

        $product=$this->product();

        $this->actingAs($user)->post(route('pdv.store'),[
            'payment_method'=>'pix',
            'items'=>[[
                'item_type'=>'product',
                'product_id'=>$product->id,
                'quantity'=>'1.000',
                'discount'=>'0.00',
            ]],
        ])->assertSessionHasNoErrors();

        $job=FiscalDocumentJob::query()->where('document_type','nfce')->firstOrFail();

        $this->assertSame('offline',$job->emission_mode);
        $this->assertNotNull($job->contingency_started_at);
        $this->assertStringContainsString('Indisponibilidade',$job->contingency_reason);
    }

    public function test_authorized_nfce_can_enter_cancellation_queue(): void
    {
        AppSetting::put('fiscal','enabled',true);
        AppSetting::put('nfce','enabled',true);
        AppSetting::put('nfce','auto_from_pdv',true);

        $user=$this->user();
        $product=$this->product();

        $this->actingAs($user)->post(route('pdv.store'),[
            'payment_method'=>'pix',
            'items'=>[[
                'item_type'=>'product',
                'product_id'=>$product->id,
                'quantity'=>'1.000',
                'discount'=>'0.00',
            ]],
        ])->assertSessionHasNoErrors();

        $job=FiscalDocumentJob::query()->where('document_type','nfce')->firstOrFail();
        $job->update([
            'status'=>'authorized',
            'access_key'=>str_repeat('1',44),
            'protocol'=>'135260000000001',
            'authorized_at'=>now(),
        ]);

        $this->actingAs($user)->post(route('pdv.nfce.cancel',$job),[
            'reason'=>'Venda cancelada antes da saída da mercadoria do estabelecimento.',
        ])->assertSessionHasNoErrors();

        $job=$job->fresh();
        $this->assertSame('pending',$job->cancellation_status);
        $this->assertNotNull($job->cancellation_requested_at);
        $this->assertStringContainsString('Venda cancelada',$job->cancellation_reason);
    }

    public function test_billing_settings_generate_pix_copy_and_paste(): void
    {
        AppSetting::put('billing','enabled',true);
        AppSetting::put('billing','pix_key','financeiro@example.com');
        AppSetting::put('billing','fine_percent','2.0000');
        AppSetting::put('billing','interest_monthly_percent','1.0000');

        $entry=FinancialEntry::query()->create([
            'type'=>'receivable',
            'status'=>'open',
            'description'=>'Mensalidade',
            'issue_date'=>'2026-09-01',
            'competence_date'=>'2026-09-01',
            'due_date'=>today()->subDays(10)->toDateString(),
            'amount'=>'100.00',
            'paid_amount'=>'0.00',
        ]);

        $summary=app(BillingService::class)->summary($entry);

        $this->assertNotNull($summary);
        $this->assertNotEmpty($summary['pix_payload']);
        $this->assertStringStartsWith('00020126',$summary['pix_payload']);
        $this->assertGreaterThan(100,$summary['charge']);
    }

    public function test_accounting_export_writes_csv(): void
    {
        Storage::fake('local');

        FinancialEntry::query()->create([
            'type'=>'payable',
            'status'=>'open',
            'description'=>'Aluguel',
            'issue_date'=>'2026-10-02',
            'competence_date'=>'2026-10-02',
            'due_date'=>'2026-10-10',
            'amount'=>'500.00',
            'paid_amount'=>'0.00',
            'cost_center'=>'Administrativo',
        ]);

        AppSetting::put('accounting','office_name','Martins Contabilidade');
        AppSetting::put('accounting','accountant_name','Responsável Contábil');
        AppSetting::put('accounting','accounting_system','Domínio');

        $path=app(AccountingExportService::class)->generate('2026-10');

        Storage::disk('local')->assertExists($path);
        Storage::disk('local')->assertExists('accounting/exports/2026/nextor-contabil-2026-10.meta.json');
        $this->assertStringContainsString('nextor-contabil-2026-10.csv',$path);

        $metadata=json_decode(
            Storage::disk('local')->get('accounting/exports/2026/nextor-contabil-2026-10.meta.json'),
            true
        );
        $this->assertSame('Martins Contabilidade',$metadata['accounting']['office_name']);
        $this->assertSame('Responsável Contábil',$metadata['accounting']['accountant_name']);
        $this->assertSame('Domínio',$metadata['accounting']['accounting_system']);
        $this->assertSame('csv',$metadata['format']);
    }

    public function test_print_currency_prefix_setting_is_honored(): void
    {
        $user=$this->user();
        $data=$this->saleData($this->product());
        $data['source']='pdv';

        $sale=app(SalesService::class)->create($data,$user->id);

        CompanySetting::current()->update(['show_currency_prefix'=>false]);

        $this->actingAs($user)
            ->get(route('pdv.receipt',$sale))
            ->assertOk()
            ->assertDontSee('R$');

        CompanySetting::current()->update(['show_currency_prefix'=>true]);

        $this->actingAs($user)
            ->get(route('pdv.receipt',$sale))
            ->assertOk()
            ->assertSee('R$');
    }

    public function test_system_preferences_are_exposed_to_admin_and_pdv_layouts(): void
    {
        AppSetting::put('system','compact_mode',false);
        AppSetting::put('system','show_tutorials',false);
        AppSetting::put('system','confirm_destructive_actions',false);
        AppSetting::put('system','search_delay',480);

        $user=$this->user();

        $this->actingAs($user)
            ->get(route('settings.index',['tab'=>'system']))
            ->assertOk()
            ->assertSee('system-comfortable',false)
            ->assertSee('data-show-tutorials="0"',false)
            ->assertSee('data-confirm-destructive="0"',false)
            ->assertSee('data-live-search-delay="480"',false);

        $this->actingAs($user)
            ->get(route('pdv.index'))
            ->assertOk()
            ->assertSee('data-show-tutorials="0"',false)
            ->assertSee('data-confirm-destructive="0"',false);
    }

    public function test_api_switch_and_token_protect_endpoints(): void
    {
        AppSetting::put('integrations','api_enabled',true);
        AppSetting::put('integrations','api_token','token-teste-seguro',true);
        $this->product();

        $this->get('/api/nextor/products')->assertStatus(401);

        $this->withToken('token-teste-seguro')
            ->get('/api/nextor/products')
            ->assertOk()
            ->assertJsonPath('data.0.sku','SET001');
    }
}
