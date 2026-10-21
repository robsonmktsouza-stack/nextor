<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class CompanyBillingLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'Administradora',
            'email' => 'admin-onboarding@example.test',
            'password' => 'senha-segura-testes',
        ]);
    }

    private function customerData(?string $expiry = null): array
    {
        return [
            'legal_name' => 'EMPRESA NOVA LTDA',
            'cnpj' => '11.222.333/0001-81',
            'email' => 'cliente-novo@example.test',
            'subdomain' => 'empresa-nova',
            'plan' => 'standard',
            'expires_at' => $expiry,
        ];
    }

    public function test_new_company_with_priced_plan_prepares_one_draft_without_extra_pages(): void
    {
        $this->actingAs($this->admin());
        Plan::query()->where('code', 'standard')->update([
            'monthly_price' => '150.00', 'period_months' => 1,
        ]);
        $expiry = today()->addDays(15)->toDateString();

        $this->post(route('clients.store'), $this->customerData($expiry))
            ->assertRedirect()->assertSessionHas('success', 'Conta cadastrada. Fatura preparada em rascunho.');

        $company = Company::query()->sole();
        $invoice = Invoice::query()->sole();
        $this->assertSame('active', $company->currentStatus());
        $this->assertSame($expiry, $company->expires_at->toDateString());
        $this->assertSame('draft', $invoice->status);
        $this->assertSame('onboarding', $invoice->origin);
        $this->assertSame('150.00', $invoice->amount);
        $this->assertSame($expiry, $invoice->due_date->toDateString());
        $this->assertSame($company->expires_at->copy()->addDay()->toDateString(),
            $invoice->period_start->toDateString());
        $this->assertSame('FAT-000001', $invoice->number);
        $this->get(route('clients.show', $company))->assertOk();
        $this->get(route('invoices.index'))->assertOk()->assertSee('FAT-000001');
        $this->assertTrue($company->events()->where('event_type', 'invoice_created')->exists());
    }

    public function test_new_company_without_date_is_not_created_with_permanent_license(): void
    {
        $this->actingAs($this->admin());
        Plan::query()->where('code', 'standard')->update(['monthly_price' => '89.90']);
        $data = $this->customerData();
        unset($data['expires_at']);
        $this->post(route('clients.store'), $data)->assertRedirect();
        $company = Company::query()->sole();
        $invoice = Invoice::query()->sole();
        $this->assertSame(today()->toDateString(), $company->expires_at->toDateString());
        $this->assertSame(today()->addDay()->toDateString(), $invoice->period_start->toDateString());
        $this->assertSame('89.90', $invoice->amount);
    }

    public function test_plan_without_price_keeps_account_but_does_not_invent_a_charge(): void
    {
        $this->actingAs($this->admin());
        $this->post(route('clients.store'), $this->customerData())
            ->assertRedirect()->assertSessionHas('success',
                'Conta cadastrada. Configure o preço do plano para preparar a fatura.');
        $this->assertDatabaseCount('companies', 1);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_full_confirmed_payment_of_prepared_invoice_renews_once_and_partial_does_not(): void
    {
        $this->actingAs($this->admin());
        Plan::query()->where('code', 'standard')->update(['monthly_price' => '150.00']);
        $this->post(route('clients.store'), $this->customerData(today()->addDays(20)->toDateString()))
            ->assertRedirect();

        $company = Company::query()->sole();
        $invoice = Invoice::query()->sole();
        $oldExpiry = $company->expires_at->toDateString();
        $this->post(route('invoices.issue', $invoice))->assertRedirect();
        $pay = ['method'=>'pix', 'paid_on'=>today()->toDateString(), 'reference'=>'PAY-CONFIRMED'];

        $this->post(route('invoices.pay', $invoice), $pay + ['amount'=>'50.00'])->assertRedirect();
        $this->assertSame($oldExpiry, $company->fresh()->expires_at->toDateString());
        $this->assertNull($invoice->fresh()->license_applied_at);

        $this->post(route('invoices.pay', $invoice), $pay + ['amount'=>'100.00'])->assertRedirect();
        $this->assertSame($invoice->period_end->toDateString(), $company->fresh()->expires_at->toDateString());
        $this->assertTrue($invoice->fresh()->licenseApplicationActive());
        $this->assertSame('active', $company->fresh()->license_status);
        $this->assertSame(1, $company->events()->where('event_type', 'invoice_license_applied')->count());
        $this->post(route('invoices.apply-license', $invoice))->assertSessionHasErrors('invoice');
    }

    public function test_recurring_invoices_are_drafts_once_per_cycle_and_use_new_plan_price(): void
    {
        $this->actingAs($this->admin());
        Plan::query()->where('code', 'standard')->update([
            'monthly_price' => '100.00', 'period_months' => 1,
        ]);
        $this->post(route('clients.store'), $this->customerData(today()->toDateString()))
            ->assertRedirect();
        $first = Invoice::query()->sole();

        // A draft is not considered a completed/issued billing cycle.
        Carbon::setTestNow($first->period_end->copy()->subDays(5)->startOfDay());
        $this->artisan('control:invoices-prepare')->assertExitCode(0);
        $this->assertDatabaseCount('invoices', 1);

        $this->post(route('invoices.issue', $first))->assertRedirect();
        Plan::query()->where('code', 'standard')->update(['monthly_price' => '120.00']);
        $this->artisan('control:invoices-prepare')->assertExitCode(0);
        $this->assertDatabaseCount('invoices', 2);

        $second = Invoice::query()->orderByDesc('id')->firstOrFail();
        $this->assertSame('recurring', $second->origin);
        $this->assertSame('draft', $second->status);
        $this->assertSame('120.00', $second->amount);
        $this->assertSame('100.00', $first->fresh()->amount);
        $this->assertSame($first->period_end->copy()->addDay()->toDateString(),
            $second->period_start->toDateString());
        $this->artisan('control:invoices-prepare')->assertExitCode(0);
        $this->assertDatabaseCount('invoices', 2);

        // An automated cycle must not email or initiate an external charge.
        $this->assertDatabaseCount('client_email_deliveries', 0);
    }

    public function test_expired_or_suspended_accounts_are_not_billed_recursively(): void
    {
        $this->actingAs($this->admin());
        Plan::query()->where('code', 'standard')->update(['monthly_price' => '100.00']);
        $this->post(route('clients.store'), $this->customerData(today()->toDateString()))
            ->assertRedirect();
        $company = Company::query()->sole();
        $first = Invoice::query()->sole();
        $this->post(route('invoices.issue', $first))->assertRedirect();
        $company->update(['license_status' => 'suspended']);
        Carbon::setTestNow($first->period_end->copy()->subDays(4)->startOfDay());
        $this->artisan('control:invoices-prepare')->assertExitCode(0);
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_manual_service_invoice_does_not_start_subscription_recurring_drafts(): void
    {
        $this->actingAs($this->admin());
        Plan::query()->where('code', 'standard')->update(['monthly_price' => '100.00']);
        $this->post(route('clients.store'), $this->customerData(today()->toDateString()))
            ->assertRedirect();
        $company = Company::query()->sole();
        $onboarding = Invoice::query()->sole();
        $this->post(route('invoices.cancel', $onboarding), ['reason' => 'Ciclo de teste cancelado'])
            ->assertRedirect();

        $this->post(route('invoices.store'), [
            'company_id' => $company->id,
            'description' => 'Serviço avulso',
            'period_start' => today()->toDateString(),
            'period_end' => today()->addDays(8)->toDateString(),
            'issue_date' => today()->toDateString(),
            'due_date' => today()->toDateString(),
            'amount' => '35.00',
        ])->assertRedirect();
        $manual = Invoice::query()->where('status', '!=', 'cancelled')->firstOrFail();
        $this->assertSame('manual', $manual->origin);
        $this->post(route('invoices.issue', $manual))->assertRedirect();

        Carbon::setTestNow($manual->period_end->copy()->subDays(4)->startOfDay());
        $this->artisan('control:invoices-prepare')->assertExitCode(0);
        $this->assertSame(2, Invoice::query()->count());
    }

    public function test_backdated_initial_expiry_is_rejected_without_any_mutations(): void
    {
        $this->actingAs($this->admin());
        Plan::query()->where('code', 'standard')->update(['monthly_price' => '150.00']);
        $this->post(route('clients.store'), $this->customerData(today()->subDay()->toDateString()))
            ->assertSessionHasErrors('expires_at');
        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('invoices', 0);
    }
}
