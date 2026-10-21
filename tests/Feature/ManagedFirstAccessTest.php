<?php

namespace Tests\Feature;

use App\Models\CompanySetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ManagedFirstAccessTest extends TestCase
{
    use RefreshDatabase;

    private function configureIdentity(): void
    {
        config()->set('instance.id', 'c_42');
        config()->set('instance.cnpj', '11222333000181');
        config()->set('instance.subdomain', 'equipamentos-piloto');
        config()->set('instance.base_domain', 'lumeron.test');
        config()->set('app.url', 'https://equipamentos-piloto.lumeron.test');
    }

    private function managedArgs(): array
    {
        return [
            '--cnpj' => '11222333000181',
            '--company-name' => 'Equipamentos Piloto LTDA',
            '--admin-name' => 'Administração',
            '--admin-email' => 'gestor@example.test',
            '--admin-login' => 'gestor',
        ];
    }

    public function test_managed_provision_creates_only_local_company_and_owner_with_invitation(): void
    {
        $this->configureIdentity();
        $file = storage_path('app/private/managed-first-access.json');
        @unlink($file);
        try {
            $this->artisan('erp:bootstrap-managed', $this->managedArgs())
                ->assertExitCode(0);
            $this->assertDatabaseHas('company_settings', [
                'document' => '11222333000181',
                'legal_name' => 'Equipamentos Piloto LTDA',
            ]);
            $this->assertDatabaseHas('users', [
                'email' => 'gestor@example.test',
                'username' => 'gestor',
                'role' => 'admin',
            ]);
            $this->assertDatabaseCount('users', 1);
            $this->assertDatabaseCount('password_reset_tokens', 1);
            $this->assertFileExists($file);
            $invite = json_decode((string) file_get_contents($file), true);
            $this->assertSame('gestor@example.test', $invite['email']);
            $this->assertStringStartsWith(
                'https://equipamentos-piloto.lumeron.test/reset-password/',
                $invite['url']
            );
            $this->assertStringContainsString('email=gestor%40example.test', $invite['url']);
            $this->assertNotEmpty(User::query()->firstOrFail()->password);
            // The private invitation, not an initial plaintext password, is
            // the only credential the trusted Control worker must deliver.
            $this->assertArrayNotHasKey('password', $invite);
        } finally {
            @unlink($file);
        }
    }

    public function test_managed_provision_rejects_wrong_issuer_without_creating_login(): void
    {
        $this->configureIdentity();
        $args = $this->managedArgs();
        $args['--cnpj'] = '00000000000000';
        $this->artisan('erp:bootstrap-managed', $args)->assertExitCode(1);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('company_settings', 0);
    }

    public function test_existing_mismatched_user_prevents_account_takeover(): void
    {
        $this->configureIdentity();
        User::query()->create([
            'name' => 'Outro usuário',
            'email' => 'owner-old@example.test',
            'username' => 'owner-old',
            'password' => 'random-long-password',
        ]);
        $this->artisan('erp:bootstrap-managed', $this->managedArgs())
            ->assertExitCode(1);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseMissing('users', [
            'email' => 'gestor@example.test',
        ]);
    }
}
