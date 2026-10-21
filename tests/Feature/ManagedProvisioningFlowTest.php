<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ManagedProvisioningJob;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ManagedProvisioningFlowTest extends TestCase
{
    use RefreshDatabase;

    private function registerCompany(): Company
    {
        $admin = User::query()->create([
            'name' => 'Operador',
            'email' => 'operador-novos@example.test',
            'password' => 'senha-longa-de-teste',
        ]);
        $this->actingAs($admin);
        Plan::query()->where('code','standard')->update(['monthly_price'=>'120.00']);
        $this->post(route('clients.store'), [
            'legal_name' => 'PILOTO EQUIPAMENTOS LTDA',
            'cnpj' => '11.222.333/0001-81',
            'email' => 'piloto@example.test',
            'contact_name' => 'Responsável',
            'admin_login' => 'gestor',
            'subdomain' => 'piloto-erp',
            'plan' => 'standard',
            'expires_at' => today()->addDays(10)->toDateString(),
        ])->assertRedirect();

        return Company::query()->sole();
    }

    public function test_one_registration_creates_one_company_one_invoice_and_one_provisioning_job(): void
    {
        $company = $this->registerCompany();
        $this->assertDatabaseCount('companies', 1);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('managed_provisioning_jobs', 1);
        $job = ManagedProvisioningJob::query()->sole();
        $this->assertSame($company->id, $job->company_id);
        $this->assertSame('queued', $job->status);
        $this->assertNull($company->installation_url);
        $this->assertNull($company->last_seen_at);
        $this->get(route('clients.show', $company))->assertOk()
            ->assertSee('Aguardando preparação');
    }

    public function test_disabled_local_provisioner_cannot_touch_queued_customer(): void
    {
        $this->registerCompany();
        config()->set('managed_provisioning.enabled', false);
        $this->artisan('control:provision-accounts')->assertExitCode(1);
        $this->assertDatabaseHas('managed_provisioning_jobs', [
            'status' => 'queued',
            'attempts' => 0,
        ]);
        $this->assertDatabaseCount('companies', 1);
    }

    public function test_provisioning_status_is_visible_without_changing_customer_screen(): void
    {
        $company = $this->registerCompany();
        $job = $company->provisioningJob;
        $job->update(['status' => 'processing']);
        $this->get(route('clients.details', $company))->assertOk()
            ->assertSee('Preparando acesso');
        $job->update(['status' => 'failed']);
        $this->get(route('clients.details', $company))->assertOk()
            ->assertSee('Preparação precisa de revisão');
        $job->update(['status' => 'ready']);
        $this->get(route('clients.details', $company))->assertOk()
            ->assertSee('Acesso preparado');
    }
}
