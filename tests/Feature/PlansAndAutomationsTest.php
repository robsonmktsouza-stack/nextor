<?php

namespace Tests\Feature;

use App\Models\AutomationDelivery;
use App\Models\AutomationRule;
use App\Models\Company;
use App\Models\Plan;
use App\Models\User;
use App\Services\AutomationRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PlansAndAutomationsTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create([
            'name' => 'Administradora',
            'email' => 'admin-planos@example.test',
            'password' => 'senha-longa-de-teste',
        ]);
    }

    private function company(array $overrides = []): Company
    {
        return Company::create(array_merge([
            'instance_id' => (string) Str::uuid(),
            'legal_name' => 'EMPRESA LTDA',
            'cnpj' => '11222333000181',
            'email' => 'cliente@example.test',
            'subdomain' => 'empresa-teste',
            'plan' => 'standard',
            'license_status' => 'active',
            'expires_at' => today()->addDays(5)->toDateString(),
            'modules' => ['pdv', 'fiscal'],
            'license_secret' => bin2hex(random_bytes(48)),
        ], $overrides));
    }

    public function test_old_subscription_codes_are_migrated_to_editable_plan_catalogue(): void
    {
        $this->assertSame(['standard', 'advanced', 'full'], Plan::query()->orderBy('id')->pluck('code')->all());
        $this->assertSame('Padrão', Plan::query()->where('code', 'standard')->value('name'));
        $this->actingAs($this->user())->get('/plans')->assertOk()->assertSee('Padrão');
    }

    public function test_plan_can_be_created_and_used_in_a_new_company_account(): void
    {
        $this->actingAs($this->user());
        $this->post('/plans', [
            'code' => 'profissional',
            'name' => 'Profissional',
            'description' => 'Plano editável',
            'monthly_price' => '149.90',
            'period_months' => 3,
            'modules' => ['finance', 'products'],
            'active' => '1',
        ])->assertRedirect('/plans');
        $plan = Plan::query()->where('code', 'profissional')->firstOrFail();
        $this->assertSame('149.90', $plan->monthly_price);
        $this->get('/clients/create')->assertOk()->assertSee('Profissional');

        $this->post('/clients', [
            'legal_name' => 'CLIENTE PROFISSIONAL LTDA',
            'cnpj' => '11.222.333/0001-81',
            'email' => 'cliente@example.test',
            'subdomain' => 'cliente-pro',
            'plan' => 'profissional',
            // Absent modules: inherit default from selected plan server-side.
        ])->assertRedirect();
        $company = Company::query()->where('subdomain', 'cliente-pro')->firstOrFail();
        $this->assertSame('profissional', $company->plan);
        $this->assertSame(['finance', 'products'], $company->modules);
    }

    public function test_plan_edit_does_not_retroactively_change_customer_modules(): void
    {
        $this->actingAs($this->user());
        $company = $this->company();
        $plan = Plan::query()->where('code', 'standard')->firstOrFail();

        $this->put(route('plans.update', $plan), [
            'code' => 'standard',
            'name' => 'Padrão Personalizado',
            'monthly_price' => '100.00',
            'period_months' => 1,
            'modules' => ['finance'],
            'active' => '1',
        ])->assertRedirect(route('plans.index'));

        $this->assertSame('Padrão Personalizado', $plan->fresh()->name);
        $this->assertSame(['pdv', 'fiscal'], $company->fresh()->modules);
        $this->get('/clients')->assertSee('Padrão Personalizado');
        $this->put(route('plans.update', $plan), [
            'code' => 'different',
            'name' => 'Inválido',
            'period_months' => 1,
            'modules' => ['pdv'],
            'active' => '1',
        ])->assertSessionHasErrors('code');
    }

    public function test_archived_plan_stays_on_existing_license_but_cannot_be_sold_to_new_account(): void
    {
        $this->actingAs($this->user());
        $company = $this->company();
        $plan = Plan::query()->where('code', 'standard')->firstOrFail();
        $this->put(route('plans.update', $plan), [
            'code' => $plan->code,
            'name' => $plan->name,
            'period_months' => 1,
            'modules' => ['pdv'],
            'active' => '0',
        ])->assertRedirect(route('plans.index'));
        $this->assertSame('standard', $company->fresh()->plan);
        $this->get(route('clients.show', $company))->assertOk()->assertSee('Padrão');
        $this->post('/clients', [
            'legal_name' => 'NOVO CLIENTE LTDA',
            'cnpj' => '39.323.356/0001-00',
            'email' => 'cliente2@example.test',
            'subdomain' => 'novocli',
            'plan' => 'standard',
        ])->assertSessionHasErrors('plan');
    }

    public function test_automation_is_off_by_default_and_notifications_are_idempotent(): void
    {
        $company = $this->company();
        $rule = AutomationRule::query()->where('code', 'expiry_soon')->firstOrFail();
        $runner = app(AutomationRunner::class);
        $this->assertSame(0, $runner->run()['rules']);
        $this->assertDatabaseCount('automation_deliveries', 0);

        $rule->update(['enabled' => true, 'days' => 7]);
        $first = $runner->run();
        $second = $runner->run();
        $this->assertSame(1, $first['rules']);
        $this->assertSame(1, $first['new_events']);
        $this->assertSame(0, $second['new_events']);
        $this->assertDatabaseCount('automation_deliveries', 1);
        $this->assertSame('automation_expiry_soon', $company->events()->first()->event_type);

        // Renew to a new due date: a new cycle may generate a fresh notice.
        $company->update(['expires_at' => today()->addDays(6)->toDateString()]);
        $this->assertSame(1, $runner->run()['new_events']);
    }

    public function test_expiry_and_offline_rules_report_only_internal_events(): void
    {
        $company = $this->company([
            'expires_at' => today()->subDay()->toDateString(),
            'last_seen_at' => now()->subDays(4),
        ]);
        AutomationRule::query()->where('code', 'expired')->update(['enabled' => true]);
        AutomationRule::query()->where('code', 'offline')->update(['enabled' => true, 'days' => 2]);

        $summary = app(AutomationRunner::class)->run();
        $this->assertSame(2, $summary['new_events']);
        $this->assertDatabaseCount('automation_deliveries', 2);
        $this->assertCount(2, $company->events);
        $this->assertSame(0, app(AutomationRunner::class)->run()['new_events']);
        $this->assertSame('active', $company->fresh()->license_status);
        $this->assertSame('expired', $company->fresh()->currentStatus());
    }

    public function test_admin_can_update_rules_and_manually_check_with_audit(): void
    {
        $this->actingAs($this->user());
        $company = $this->company();
        $rule = AutomationRule::query()->where('code', 'expiry_soon')->firstOrFail();
        $this->get('/automations')->assertOk()->assertSee('Licenças próximas');
        $this->put(route('automations.update', $rule), ['enabled' => '1', 'days' => 7])
            ->assertRedirect();
        $this->post(route('automations.run'))->assertRedirect()
            ->assertSessionHas('success');
        $this->assertSame(1, $company->events()->count());
        $this->assertSame(1, AutomationDelivery::count());
    }

    public function test_plan_and_automation_administration_is_private(): void
    {
        $this->get('/plans')->assertRedirect('/login');
        $this->get('/automations')->assertRedirect('/login');
        $this->post('/automations/run')->assertRedirect('/login');
    }
}
