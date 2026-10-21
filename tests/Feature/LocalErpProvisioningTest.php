<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ManagedProvisioningJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LocalErpProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private function sample(): Company
    {
        $company = Company::query()->create([
            'instance_id'=>'d5148c8b-1953-4278-b310-e54199139888',
            'legal_name'=>'PILOTO CONTABILIDADE LTDA',
            'cnpj'=>'11222333000181',
            'email'=>'piloto@example.test',
            'subdomain'=>'martinscontabilidade',
            'admin_login'=>'Robson',
            'plan'=>'standard',
            'license_status'=>'active',
            'expires_at'=>today()->addDays(20)->toDateString(),
            'license_secret'=>bin2hex(random_bytes(48)),
        ]);
        ManagedProvisioningJob::query()->create([
            'company_id'=>$company->id,
            'status'=>'queued',
        ]);
        return $company;
    }

    public function test_local_provisioning_does_not_run_implicitly(): void
    {
        $company = $this->sample();
        config()->set('managed_provisioning.local_enabled', false);
        $this->artisan('control:provision-local --all')->assertExitCode(1);
        $this->assertDatabaseHas('managed_provisioning_jobs', [
            'company_id'=>$company->id, 'status'=>'queued','attempts'=>0,
        ]);
    }

    public function test_existing_company_can_be_selected_without_recreating_it(): void
    {
        $company = $this->sample();
        $this->artisan('control:provision-local '.$company->id.' --preview')
            ->assertExitCode(1);
        $this->assertDatabaseCount('companies',1);
        $this->assertDatabaseCount('managed_provisioning_jobs',1);
        $this->assertDatabaseHas('managed_provisioning_jobs',[
            'company_id'=>$company->id, 'status'=>'queued','attempts'=>0,
        ]);
    }

    public function test_first_access_requires_existing_ready_provisioning_and_panel_login(): void
    {
        $company = $this->sample();
        $this->get(route('clients.first-access', $company))->assertRedirect('/login');
        $user = User::query()->create([
            'name'=>'Operator', 'email'=>'admin@example.test', 'password'=>'long-test-secret',
        ]);
        $this->actingAs($user);
        $this->get(route('clients.first-access',$company))->assertNotFound();
        $company->provisioningJob()->update(['status'=>'ready']);
        // Ready flag alone is never sufficient to reveal an invitation:
        // the ERP folder and signed identity file must exist and match.
        $this->get(route('clients.first-access',$company))->assertNotFound();
    }

    public function test_local_provisioner_reports_failing_stage_without_dumping_sensitive_output(): void
    {
        $service = app(\App\Services\LocalErpProvisioner::class);
        $this->assertStringContainsString('pdo_sqlite',
            $service->failureHint('migrations', 'could not find driver'));
        $this->assertStringContainsString('extensão PHP intl',
            $service->failureHint('composer', 'package requires ext-intl *'));
        $this->assertStringContainsString('Composer',
            $service->failureHint('composer', 'process ended with exit code 1'));
        $this->assertStringContainsString('Control não está acessível',
            $service->failureHint('license-binding', 'O Lumeron Control não está acessível nesse endereço'));
        $this->assertStringNotContainsString('secret123',
            $service->failureHint('first-access', 'Something failed APP_KEY=secret123'));
    }

    public function test_local_provisioner_rejects_unsafe_company_slug(): void
    {
        $company=$this->sample();
        $company->subdomain='../other-client';
        $this->expectException(\RuntimeException::class);
        app(\App\Services\LocalErpProvisioner::class)->preview($company);
    }
}
