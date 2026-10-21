<?php

namespace Tests\Feature;

use App\Models\AutomationRule;
use App\Models\Company;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class LicenseListingStyleTest extends TestCase
{
    use RefreshDatabase;

    private function login(): void
    {
        $user = User::create([
            'name' => 'Administrador',
            'email' => 'admin-grelha@example.test',
            'password' => 'senha-para-testes',
        ]);
        $this->actingAs($user);
    }

    private function company(): Company
    {
        return Company::create([
            'instance_id' => (string) Str::uuid(),
            'legal_name' => 'EMPRESA DE LISTAGEM LTDA',
            'cnpj' => '11222333000181',
            'email' => 'licenca@example.test',
            'subdomain' => 'empresa-listagem',
            'plan' => 'standard',
            'license_status' => 'active',
            'expires_at' => today()->addDays(20)->toDateString(),
            'modules' => ['pdv', 'products'],
            'license_secret' => bin2hex(random_bytes(48)),
        ]);
    }

    public function test_license_listing_uses_compact_grid_and_switches_without_bulk_checkboxes(): void
    {
        $this->login();
        $company = $this->company();

        $this->get('/licenses')->assertOk()
            ->assertSee('data-license-grid', false)
            ->assertSee('data-license-export-table', false)
            ->assertSee('data-license-filter-toggle', false)
            ->assertSee('data-license-switch-form', false)
            ->assertSee('role="switch"', false)
            ->assertSee('aria-label="Suspender licença de EMPRESA DE LISTAGEM LTDA"', false)
            ->assertSee('data-page-size', false)
            ->assertSee($company->legal_name)
            ->assertSee('data-account-details', false)
            ->assertDontSee('data-row-select')
            ->assertDontSee('data-check-all');

        $this->get('/licenses?per_page=50&status=active')->assertOk()
            ->assertSee('50 linhas por página')
            ->assertSee('option value="50" selected', false)
            ->assertSee('EMPRESA DE LISTAGEM LTDA');
    }

    public function test_status_switch_keeps_server_authorization_and_audit(): void
    {
        $this->login();
        $company = $this->company();

        $this->post(route('clients.status', $company), ['action' => 'suspend'])
            ->assertRedirect();
        $this->assertSame('suspended', $company->fresh()->currentStatus());
        $this->get('/licenses')->assertOk()
            ->assertSee('aria-label="Ativar licença de EMPRESA DE LISTAGEM LTDA"', false)
            ->assertSee('Suspensa');

        $this->post(route('clients.status', $company), ['action' => 'activate'])
            ->assertRedirect();
        $this->assertSame('active', $company->fresh()->currentStatus());
        $this->assertCount(2, $company->events()->get());
    }

    public function test_plan_and_automation_activation_use_accessible_switches(): void
    {
        $this->login();
        $plan = Plan::query()->where('code', 'standard')->firstOrFail();
        $this->get(route('plans.edit', $plan))->assertOk()
            ->assertSee('role="switch"', false)
            ->assertSee('Disponível para novas contas');
        $this->get('/automations')->assertOk()
            ->assertSee('role="switch"', false)
            ->assertSee(AutomationRule::query()->where('code', 'offline')->value('name'));

        $company = $this->company();
        $this->get(route('clients.show', $company))->assertOk()
            ->assertSee('data-license-switch-form', false)
            ->assertSee('role="switch"', false)
            ->assertSee('Módulos autorizados');
    }

    public function test_viewing_licenses_requires_authentication(): void
    {
        $this->get('/licenses')->assertRedirect('/login');
    }
}
