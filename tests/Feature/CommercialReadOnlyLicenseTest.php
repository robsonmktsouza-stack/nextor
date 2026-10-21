<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\License\CentralLicenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class CommercialReadOnlyLicenseTest extends TestCase
{
    use RefreshDatabase;

    private const INSTANCE = 'f3f4e1ac-a808-47ca-adb3-771922a99d41';
    private const CNPJ = '11222333000181';

    private function user(): User
    {
        return User::create([
            'name' => 'Administrador',
            'username' => 'readonlyadmin',
            'email' => 'readonly@example.test',
            'password' => 'long-test-password',
            'role' => 'admin',
            'is_active' => true,
        ]);
    }

    private function license(string $status): void
    {
        $secret = str_repeat('c', 96);
        config()->set('central_license.enabled', true);
        config()->set('central_license.url', 'http://127.0.0.1:8000');
        config()->set('central_license.instance_id', self::INSTANCE);
        config()->set('central_license.secret', $secret);
        config()->set('instance.cnpj', self::CNPJ);
        Cache::forget('lumeron:central-license:v1');

        $body = json_encode([
            'instance_id' => self::INSTANCE,
            'cnpj' => self::CNPJ,
            'status' => $status,
            'plan' => 'standard',
            'modules' => ['pdv','products','finance','fiscal','services'],
            'expires_at' => now()->addMonth()->format('Y-m-d'),
            'checked_at' => now()->toIso8601String(),
        ], JSON_UNESCAPED_SLASHES);

        Http::fake(['*' => Http::response($body, 200, [
            'X-Lumeron-Signature' => hash_hmac('sha256', $body, $secret),
        ])]);
    }

    public function test_suspended_user_can_view_actual_erp_pages_with_compact_notice(): void
    {
        $this->license('suspended');
        $this->actingAs($this->user());
        $this->get('/products')
            ->assertOk()
            ->assertSee('data-commercial-readonly', false)
            ->assertSee('Modo de consulta')
            ->assertSee('Assinatura suspensa');
    }

    public function test_commercial_writes_are_blocked_by_server_for_every_http_method(): void
    {
        $this->license('suspended');
        $this->actingAs($this->user());

        foreach (['POST','PUT','PATCH','DELETE'] as $method) {
            Route::match([$method], '/__read-only-edit', fn () => response('unsafe-executed', 200))
                ->middleware(['auth', 'control.license']);

            $this->json($method, '/__read-only-edit')
                ->assertStatus(423)
                ->assertJsonPath('code', 'LUMERON_LICENSE_READ_ONLY')
                ->assertJsonPath('license_status', 'suspended')
                ->assertDontSee('unsafe-executed');
        }
    }

    public function test_browser_form_write_returns_to_page_with_warning(): void
    {
        $this->license('suspended');
        $this->actingAs($this->user());

        Route::post('/__read-only-submit', fn () => 'unsafe-executed')
            ->middleware(['auth', 'control.license']);

        $this->from('/products')->post('/__read-only-submit', [])
            ->assertStatus(303)->assertRedirect('/products')
            ->assertSessionHas('warning');
    }

    public function test_fiscal_continuity_is_specific_and_cannot_create_new_notes(): void
    {
        $this->license('suspended');
        $this->actingAs($this->user());

        Route::post('/__fiscal-existing-cancel', fn () => 'regularizacao-permitida')
            ->middleware(['auth','control.license'])->name('fiscal.nfce.cancel');
        Route::post('/__fiscal-new-invoice', fn () => 'nova-nota-nao-permitida')
            ->middleware(['auth','control.license'])->name('fiscal.nfce.emit');
        Route::post('/__fiscal-rule-edit', fn () => 'regra-mudou')
            ->middleware(['auth','control.license'])->name('fiscal.tax-rules.update');

        $this->post('/__fiscal-existing-cancel')
            ->assertOk()->assertSee('regularizacao-permitida');
        $this->postJson('/__fiscal-new-invoice')
            ->assertStatus(423);
        $this->postJson('/__fiscal-rule-edit')
            ->assertStatus(423);
    }

    public function test_expired_and_unverified_licenses_have_distinct_messages(): void
    {
        $this->license('expired');
        $this->actingAs($this->user());
        $this->get('/products')->assertOk()->assertSee('Assinatura vencida');

        Cache::forget('lumeron:central-license:v1');
        Http::fake(['*' => Http::response(['message' => 'indisponível'], 503)]);
        // No previously signed state; the expired status from this test may
        // remain in the local database after a GET. Clear to simulate expiry
        // of offline grace.
        \Illuminate\Support\Facades\DB::table('central_license_states')->delete();
        $this->get('/products')->assertOk()
            ->assertSee('Verificação de licença indisponível');
    }

    public function test_reactivated_account_can_write_normally(): void
    {
        $this->license('active');
        $this->actingAs($this->user());

        Route::post('/__license-save-active', fn () => response('salvo', 200))
            ->middleware(['auth','control.license']);
        $this->post('/__license-save-active')->assertOk()->assertSee('salvo');
        $this->get('/products')->assertOk()->assertDontSee('data-commercial-readonly');
    }

    public function test_logout_stays_available_during_suspension(): void
    {
        $this->license('suspended');
        $this->actingAs($this->user());
        $this->post('/logout')->assertRedirect();
        $this->assertGuest();
    }
}
