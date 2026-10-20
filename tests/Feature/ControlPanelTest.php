<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ControlPanelTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create([
            'name' => 'Administrador',
            'email' => 'admin@lumeron.test',
            'password' => 'long-password-for-testing',
        ]);
    }

    private function company(): Company
    {
        return Company::create([
            'instance_id' => 'a028047b-0474-46b3-a31a-c5b3e55fd017',
            'legal_name' => 'EMPRESA DE TESTE LTDA',
            'cnpj' => '11222333000181',
            'contact_name' => 'Responsável',
            'email' => 'cliente@example.test',
            'subdomain' => 'empresa-exemplo',
            'plan' => 'standard',
            'license_status' => 'active',
            'expires_at' => now()->addMonth()->toDateString(),
            'modules' => ['pdv', 'fiscal'],
            'license_secret' => bin2hex(random_bytes(48)),
        ]);
    }

    public function test_public_visitors_cannot_manage_customers(): void
    {
        $this->get('/clients')->assertRedirect('/login');
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/login')->assertOk();
    }

    public function test_admin_can_create_client_and_secret_is_encrypted(): void
    {
        $this->actingAs($this->user());
        $response = $this->post('/clients', [
            'legal_name' => 'EMPRESA DE TESTE LTDA',
            'cnpj' => '11.222.333/0001-81',
            'contact_name' => 'Ana',
            'email' => 'ana@example.test',
            'subdomain' => 'empresa-a',
            'plan' => 'standard',
            'modules' => ['pdv', 'fiscal'],
        ]);
        $company = Company::sole();
        $response->assertRedirect(route('clients.show', $company))
            ->assertSessionHas('new_secret');
        $this->assertSame('11222333000181', $company->cnpj);
        $this->assertCount(2, $company->modules);
        $this->assertSame(1, $company->events()->count());
        $this->assertNotSame($company->license_secret, \Illuminate\Support\Facades\DB::table('companies')->value('license_secret'));
        $this->get('/clients/'.$company->id)->assertOk()->assertSee('EMPRESA DE TESTE LTDA');
    }

    public function test_rejects_invalid_cnpj_and_duplicate_subdomain(): void
    {
        $this->actingAs($this->user());
        $this->post('/clients', [
            'legal_name' => 'Teste',
            'cnpj' => '00.000.000/0000-00',
            'email' => 'a@example.test',
            'subdomain' => 'empresa-x',
            'plan' => 'standard',
        ])->assertSessionHasErrors('cnpj');
        $this->company();
        $this->post('/clients', [
            'legal_name' => 'Outra Empresa',
            'cnpj' => '11444777000161',
            'email' => 'a@example.test',
            'subdomain' => 'empresa-exemplo',
            'plan' => 'standard',
        ])->assertSessionHasErrors('subdomain');
    }

    public function test_suspension_renewal_and_notes_are_audited(): void
    {
        $this->actingAs($this->user());
        $company = $this->company();
        $this->post(route('clients.status', $company), ['action' => 'suspend', 'reason' => 'Atraso'])
            ->assertRedirect();
        $this->assertSame('suspended', $company->fresh()->currentStatus());
        $this->post(route('clients.status', $company), ['action' => 'activate'])
            ->assertRedirect();
        $this->post(route('clients.renew', $company), ['months' => 3])
            ->assertRedirect();
        $this->post(route('clients.note', $company), ['note' => 'Contato realizado'])
            ->assertRedirect();
        $this->assertCount(4, $company->events);
        $this->assertSame('active', $company->fresh()->currentStatus());
        $this->get('/history')->assertOk()->assertSee('Contato realizado');
    }

    public function test_secret_rotation_invalidates_previous_key(): void
    {
        $this->actingAs($this->user());
        $company = $this->company();
        $previous = $company->license_secret;
        $this->post(route('clients.rotate-secret', $company))
            ->assertRedirect(route('clients.show', $company))->assertSessionHas('new_secret');
        $this->assertNotEquals($previous, $company->fresh()->license_secret);
    }
}
