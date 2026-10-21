<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Plan;
use App\Models\User;
use App\Support\InvoiceMoney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class InvoiceFlowTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'Administradora',
            'email' => 'admin-invoices@example.test',
            'password' => 'senha-segura-teste',
        ]);
    }

    private function company(): Company
    {
        Plan::query()->where('code','standard')->update(['monthly_price'=>'100.00']);
        return Company::create([
            'instance_id' => (string) Str::uuid(),
            'legal_name' => 'EMPRESA FATURADA LTDA',
            'cnpj' => '11222333000181',
            'email' => 'faturas@example.test',
            'subdomain' => 'empresa-faturada',
            'plan' => 'standard',
            'license_status' => 'active',
            'expires_at' => today()->addDays(20)->toDateString(),
            'license_secret' => bin2hex(random_bytes(48)),
        ]);
    }

    private function invoiceData(Company $company): array
    {
        return [
            'company_id' => $company->id,
            'description' => 'Assinatura profissional',
            'period_start' => today()->addDays(21)->toDateString(),
            'period_end' => today()->addDays(21)->addMonthsNoOverflow(3)->subDay()->toDateString(),
            'issue_date' => today()->toDateString(),
            'due_date' => today()->addDays(7)->toDateString(),
        ];
    }

    private function createInvoice(Company $company): Invoice
    {
        $this->post(route('invoices.store'), $this->invoiceData($company))->assertRedirect();
        return Invoice::query()->sole();
    }

    public function test_all_invoice_and_payment_routes_require_login(): void
    {
        $company = $this->company();
        $this->get(route('invoices.index'))->assertRedirect('/login');
        $this->get(route('invoices.create'))->assertRedirect('/login');
        $this->post(route('invoices.store'), $this->invoiceData($company))->assertRedirect('/login');
        $this->post('/invoices/1/issue')->assertRedirect('/login');
        $this->post('/invoices/1/pay')->assertRedirect('/login');
        $this->post('/invoices/1/cancel')->assertRedirect('/login');
    }

    public function test_creates_snapshot_uses_period_price_and_blocks_overlapping_cycles(): void
    {
        $this->actingAs($this->admin());
        $company = $this->company();
        Plan::query()->where('code', 'standard')->update([
            'monthly_price' => '125.50', 'period_months' => 3,
        ]);

        $this->get(route('invoices.create'))->assertOk()->assertSee('EMPRESA FATURADA LTDA');
        $invoice = $this->createInvoice($company);
        $this->assertSame('FAT-000001', $invoice->number);
        $this->assertSame('376.50', $invoice->amount);
        $this->assertSame('EMPRESA FATURADA LTDA', $invoice->company_name);
        $this->assertSame('standard', $invoice->plan_code);
        $this->assertSame('draft', $invoice->effectiveStatus());

        $this->post(route('invoices.store'), $this->invoiceData($company))
            ->assertSessionHasErrors('period_start');
        $this->assertDatabaseCount('invoices', 1);

        $company->update(['legal_name' => 'NOME NOVO LTDA']);
        Plan::query()->where('code', 'standard')->update(['monthly_price' => '200.00']);
        $this->assertSame('EMPRESA FATURADA LTDA', $invoice->fresh()->company_name);
        $this->assertSame('376.50', $invoice->fresh()->amount);
        $this->get(route('invoices.index'))->assertOk()->assertSee('FAT-000001');
        $this->get(route('invoices.show',$invoice))->assertOk()->assertSee('376,50');
    }

    public function test_issue_partial_pay_full_pay_and_manual_license_remains_unchanged(): void
    {
        $this->actingAs($this->admin());
        $company = $this->company();
        $before = $company->expires_at->toDateString();
        $invoice = $this->createInvoice($company);
        $this->post(route('invoices.issue', $invoice))->assertRedirect();
        $this->assertSame('issued', $invoice->fresh()->status);
        $this->put(route('invoices.update',$invoice), array_merge(
            \Illuminate\Support\Arr::except($this->invoiceData($company), ['company_id']), ['amount'=>'1.00']
        ))->assertSessionHasErrors('invoice');
        $this->assertSame('standard', $company->fresh()->plan);

        $payment = [
            'paid_on' => today()->toDateString(),
            'method' => 'pix', 'reference' => 'PIX-123',
        ];
        $this->post(route('invoices.pay',$invoice), $payment+['amount'=>'20.00'])->assertRedirect();
        $this->assertSame('partial', $invoice->fresh()->effectiveStatus());
        $this->assertSame(2000, $invoice->paidCents());
        $this->assertSame(1, InvoicePayment::query()->count());

        $this->post(route('invoices.pay',$invoice), $payment+['amount'=>'80.01'])
            ->assertSessionHasErrors('amount');
        $this->post(route('invoices.pay',$invoice), $payment+['amount'=>'80.00'])->assertRedirect();
        $this->assertSame('paid', $invoice->fresh()->effectiveStatus());
        $this->assertSame(0, $invoice->balanceCents());
        $this->post(route('invoices.pay',$invoice), $payment+['amount'=>'1.00'])
            ->assertSessionHasErrors('amount');
        $this->assertSame($before, $company->fresh()->expires_at->toDateString());
        $this->assertSame('active', $company->fresh()->license_status);
    }

    public function test_payment_reversal_is_audited_and_cancellation_requires_zero_effective_payments(): void
    {
        $this->actingAs($this->admin());
        $company = $this->company();
        $invoice = $this->createInvoice($company);
        $this->post(route('invoices.issue',$invoice))->assertRedirect();
        $this->post(route('invoices.pay',$invoice), [
            'amount' => '30.00', 'paid_on' => today()->toDateString(), 'method' => 'transferencia',
        ])->assertRedirect();

        $this->post(route('invoices.cancel',$invoice), ['reason'=>'Cancelamento solicitado'])
            ->assertSessionHasErrors('reason');
        $payment = InvoicePayment::query()->firstOrFail();
        $this->post(route('invoices.payments.reverse',[$invoice,$payment]), ['reason'=>''])->assertSessionHasErrors('reason');
        $this->post(route('invoices.payments.reverse',[$invoice,$payment]), ['reason'=>'Pagamento duplicado'])
            ->assertRedirect();
        $this->assertNotNull($payment->fresh()->reversed_at);
        $this->assertSame('Pagamento duplicado', $payment->fresh()->reversal_reason);
        $this->assertSame(0, $invoice->paidCents());
        $this->post(route('invoices.payments.reverse',[$invoice,$payment]), ['reason'=>'Outro motivo'])
            ->assertSessionHasErrors('payment');

        $this->post(route('invoices.cancel',$invoice), ['reason'=>'Cobrança indevida'])
            ->assertRedirect(route('invoices.show',$invoice));
        $this->assertSame('cancelled', $invoice->fresh()->status);
        $this->assertDatabaseCount('invoice_payments', 1);
        $this->post(route('invoices.pay',$invoice), [
            'amount'=>'1.00','paid_on'=>today()->toDateString(),'method'=>'pix',
        ])->assertSessionHasErrors('amount');
        $this->assertTrue($company->events()->where('event_type','invoice_payment_reversed')->exists());
    }

    public function test_draft_edit_is_allowed_and_invalid_amount_or_dates_are_rejected(): void
    {
        $this->actingAs($this->admin());
        $company = $this->company();
        $this->post(route('invoices.store'), $this->invoiceData($company)+['amount'=>'0.00'])
            ->assertSessionHasErrors('amount');
        $this->post(route('invoices.store'), array_merge($this->invoiceData($company), [
            'due_date' => today()->subDay()->toDateString(),
        ]))->assertSessionHasErrors('due_date');
        $invoice = $this->createInvoice($company);
        $this->get(route('invoices.edit',$invoice))->assertOk();
        $this->put(route('invoices.update',$invoice), array_merge(
            \Illuminate\Support\Arr::except($this->invoiceData($company), ['company_id']), ['amount' => '120.99', 'description'=>'Suporte avançado']
        ))->assertRedirect(route('invoices.show',$invoice));
        $this->assertSame('120.99', $invoice->fresh()->amount);
        $this->assertSame('Suporte avançado', $invoice->fresh()->description);
        $this->get(route('invoices.index').'?status=draft')->assertSee('FAT-000001');
        $this->get(route('invoices.index').'?status=paid')->assertDontSee('FAT-000001');
    }

    public function test_paid_invoice_requires_explicit_license_application_and_can_be_safely_undone(): void
    {
        $this->actingAs($this->admin());
        $company = $this->company();
        $before = $company->expires_at->toDateString();
        $invoice = $this->createInvoice($company);
        $this->post(route('invoices.apply-license',$invoice))->assertSessionHasErrors('invoice');
        $this->post(route('invoices.issue',$invoice))->assertRedirect();
        $this->post(route('invoices.apply-license',$invoice))->assertSessionHasErrors('invoice');
        $this->post(route('invoices.pay',$invoice), [
            'amount'=>'100.00', 'paid_on'=>today()->toDateString(), 'method'=>'pix',
        ])->assertRedirect();
        $this->assertSame($before, $company->fresh()->expires_at->toDateString());

        $this->post(route('invoices.apply-license',$invoice))->assertRedirect();
        $this->assertSame($invoice->period_end->toDateString(),
            $company->fresh()->expires_at->toDateString());
        $this->assertTrue($invoice->fresh()->licenseApplicationActive());

        // The existing signed ERP API must reflect this *explicit* Control renewal.
        $body = json_encode(['cnpj'=>$company->cnpj, 'version'=>'v1.0.0']);
        $stamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $canonical = "POST\n/api/license/check\n{$stamp}\n{$nonce}\n".hash('sha256', $body);
        $signature = hash_hmac('sha256', $canonical, $company->license_secret);
        $check = $this->call('POST', '/api/license/check', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_LUMERON_INSTANCE' => $company->instance_id,
            'HTTP_X_LUMERON_TIMESTAMP' => $stamp,
            'HTTP_X_LUMERON_NONCE' => $nonce,
            'HTTP_X_LUMERON_SIGNATURE' => $signature,
        ], $body);
        $check->assertOk()->assertJsonPath('expires_at', $invoice->period_end->toDateString());

        $this->post(route('invoices.apply-license',$invoice))->assertSessionHasErrors('invoice');

        $payment = InvoicePayment::query()->sole();
        $this->post(route('invoices.payments.reverse',[$invoice,$payment]),
            ['reason'=>'Pagamento equivocado'])->assertSessionHasErrors('payment');
        $this->post(route('invoices.undo-license',$invoice))->assertRedirect();
        $this->assertSame($before, $company->fresh()->expires_at->toDateString());
        $this->assertFalse($invoice->fresh()->licenseApplicationActive());
        $this->post(route('invoices.payments.reverse',[$invoice,$payment]),
            ['reason'=>'Pagamento equivocado'])->assertRedirect();
        $this->assertDatabaseHas('invoices', [
            'id'=>$invoice->id, 'license_applied_by'=>auth()->id(),
        ]);
    }

    public function test_money_validation_uses_integer_cents_and_no_floats(): void
    {
        $this->assertSame(12550, InvoiceMoney::cents('125.50'));
        $this->assertSame('125.50', InvoiceMoney::decimal(12550));
        $this->assertSame('R$ 125,50', InvoiceMoney::brl(12550));
        $this->expectException(\InvalidArgumentException::class);
        InvoiceMoney::cents('125.999');
    }
}
