<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\License\CentralLicenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class CentralLicenseGuardTest extends TestCase
{
    use RefreshDatabase;

    private const INSTANCE = 'b6fd7d52-5b56-4562-a622-2d36936b57b9';
    private const CNPJ = '11222333000181';

    private function setupLicense(): string
    {
        $secret = str_repeat('a', 96);
        config()->set('central_license.enabled', true);
        config()->set('central_license.url', 'http://control.test');
        config()->set('central_license.instance_id', self::INSTANCE);
        config()->set('central_license.secret', $secret);
        config()->set('instance.cnpj', self::CNPJ);
        Cache::forget('lumeron:central-license:v1');
        return $secret;
    }

    private function fakeResponse(string $status, array $modules, string $secret): void
    {
        $body = json_encode([
            'instance_id' => self::INSTANCE,
            'cnpj' => self::CNPJ,
            'status' => $status,
            'plan' => 'standard',
            'modules' => $modules,
            'expires_at' => now()->addMonth()->toDateString(),
            'checked_at' => now()->toIso8601String(),
        ], JSON_UNESCAPED_SLASHES);
        Http::fake(['*' => Http::response($body, 200, [
            'X-Lumeron-Signature' => hash_hmac('sha256', $body, $secret),
        ])]);
    }

    public function test_opt_in_guard_has_no_effect_when_disabled(): void
    {
        config()->set('central_license.enabled', false);
        $this->assertSame('disabled', app(CentralLicenseService::class)->state()['status']);
        Http::assertNothingSent();
    }

    public function test_signed_license_is_stored_without_fiscal_data(): void
    {
        $secret = $this->setupLicense();
        $this->fakeResponse('active', ['pdv', 'products', 'finance', 'fiscal'], $secret);
        $state = app(CentralLicenseService::class)->refresh();
        $this->assertSame('active', $state['status']);
        $this->assertSame('online', $state['source']);
        $this->assertDatabaseHas('central_license_states', ['id' => 1, 'status' => 'active']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/license/check')
            && !str_contains($request->body(), 'password')
            && !str_contains($request->body(), 'xml'));
    }

    public function test_managed_local_binding_activates_without_env_or_user_confirmation(): void
    {
        config()->set('central_license.enabled', false);
        config()->set('instance.cnpj', '');
        \App\Models\CompanySetting::current()->update(['document' => self::CNPJ]);

        $secret = str_repeat('b', 96);
        $file = storage_path('framework/testing/license-binding-'.bin2hex(random_bytes(6)).'.json');
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0700, true);
        }
        config()->set('central_license.managed_file', $file);
        try {
            file_put_contents($file, json_encode([
                'cnpj' => self::CNPJ,
                'instance_id' => self::INSTANCE,
                'secret' => $secret,
                'url' => 'http://127.0.0.1:8000',
                'grace_hours' => 72,
            ], JSON_THROW_ON_ERROR));

            $this->assertTrue(\App\Support\ManagedLicenseConfig::enabled());
            $this->fakeResponse('active', ['pdv', 'fiscal'], $secret);
            $status = app(CentralLicenseService::class)->refresh();
            $this->assertSame('active', $status['status']);
            $this->assertSame('online', $status['source']);
            $this->assertDatabaseHas('central_license_states', ['id' => 1, 'status' => 'active']);

            unlink($file);
            // A previously verified company cannot disable licensing by
            // deleting the private provisioning file.
            $this->assertTrue(\App\Support\ManagedLicenseConfig::enabled());
        } finally {
            @unlink($file);
        }
    }

    public function test_managed_binding_refuses_to_attach_different_registered_cnpj(): void
    {
        config()->set('central_license.enabled', false);
        config()->set('instance.cnpj', '');
        \App\Models\CompanySetting::current()->update(['document' => self::CNPJ]);
        $file = storage_path('framework/testing/license-mismatch-'.bin2hex(random_bytes(6)).'.json');
        config()->set('central_license.managed_file', $file);
        try {
            file_put_contents($file, json_encode([
                'cnpj' => '39323356000100',
                'instance_id' => self::INSTANCE,
                'secret' => str_repeat('b', 96),
                'url' => 'http://127.0.0.1:8000',
            ], JSON_THROW_ON_ERROR));
            $this->assertNull(\App\Support\ManagedLicenseConfig::credentials());
        } finally {
            @unlink($file);
        }
    }

    public function test_missing_or_invalid_signature_fails_closed_for_business(): void
    {
        $this->setupLicense();
        Http::fake(['*' => Http::response(['status' => 'active'], 200,
            ['X-Lumeron-Signature' => str_repeat('0', 64)])]);
        $state = app(CentralLicenseService::class)->refresh();
        $this->assertSame('unverified', $state['status']);
    }

    public function test_suspended_license_blocks_general_access_but_preserves_fiscal_route(): void
    {
        $secret = $this->setupLicense();
        $this->fakeResponse('suspended', ['pdv', 'fiscal'], $secret);
        $user = User::create([
            'name' => 'Operador', 'username' => 'operadorteste', 'email' => 'operador@teste.com',
            'password' => 'senha-para-teste', 'role' => 'admin', 'is_active' => true,
        ]);
        Route::middleware(['auth', 'control.license'])->get('/__license-business', fn () => 'permitido')->name('license.business');
        Route::middleware(['auth', 'control.license'])->get('/__license-fiscal', fn () => 'fiscal-permitido')->name('fiscal.license-legal');
        $this->actingAs($user)->get('/__license-business')->assertRedirect(route('license.status'));
        $this->actingAs($user)->get('/__license-fiscal')->assertOk()->assertSee('fiscal-permitido');
    }
}
