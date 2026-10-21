<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\License\CentralLicenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class CommercialPlanAccessTest extends TestCase
{
    use RefreshDatabase;

    private function signedLicense(array $modules, string $plan = 'custom-business', string $status = 'active'): void
    {
        $secret = str_repeat('a', 96);
        config()->set('central_license.enabled', true);
        config()->set('central_license.url', 'http://127.0.0.1:8000');
        config()->set('central_license.instance_id', 'b6fd7d52-5b56-4562-a622-2d36936b57b9');
        config()->set('central_license.secret', $secret);
        config()->set('instance.cnpj', '11222333000181');
        Cache::forget('lumeron:central-license:v1');
        $body = json_encode([
            'instance_id' => 'b6fd7d52-5b56-4562-a622-2d36936b57b9',
            'cnpj' => '11222333000181',
            'status' => $status,
            'plan' => $plan,
            'modules' => $modules,
            'expires_at' => today()->addMonth()->toDateString(),
            'checked_at' => now()->toIso8601String(),
        ], JSON_UNESCAPED_SLASHES);
        Http::fake(['*' => Http::response($body, 200, [
            'X-Lumeron-Signature' => hash_hmac('sha256', $body, $secret),
        ])]);
    }

    private function admin(): User
    {
        \App\Models\CompanySetting::current()->update([
            'document' => '11222333000181',
            'legal_name' => 'EMPRESA TESTE LTDA',
            'state' => 'SP',
            'city' => 'São Paulo',
        ]);
        return User::create([
            'name' => 'Administrador',
            'username' => 'planadmin',
            'email' => 'planadmin@example.test',
            'password' => 'senha-longa-de-teste',
            'role' => 'admin',
            'is_active' => true,
        ]);
    }

    public function test_custom_central_plan_is_accepted_and_hides_unlicensed_modules(): void
    {
        $this->signedLicense(['products'], 'my-store');
        $this->actingAs($this->admin());

        $this->get('/dashboard')->assertOk()
            ->assertSee('href="'.route('products.index').'"', false)
            ->assertDontSee('href="'.route('finance.dashboard').'"', false)
            ->assertDontSee('href="'.route('services.index').'"', false)
            ->assertDontSee('href="'.route('pdv.index').'"', false);

        $this->get('/products')->assertOk();
        $this->get('/stock')->assertOk();
        $this->get('/customers')->assertOk();
        foreach (['/finance', '/services', '/sales', '/pdv', '/fiscal/nfce/create'] as $path) {
            $this->get($path)->assertForbidden();
        }
        $this->postJson('/services', ['name' => 'Serviço bloqueado'])
            ->assertForbidden()
            ->assertJsonPath('code', 'LUMERON_MODULE_UNAVAILABLE')
            ->assertJsonPath('module', 'services');
    }

    public function test_fiscal_legal_continuity_is_preserved_without_fiscal_subscription(): void
    {
        $this->signedLicense(['products'], 'my-store');
        $this->actingAs($this->admin());
        $this->get('/fiscal')->assertOk();
        $this->get('/dashboard')->assertSee('Regularizações fiscais')
            ->assertDontSee('>Fiscal</span></a>', false);

        Route::post('/__legal-fiscal-cancel', fn () => response('cancelamento-permitido'))
            ->middleware(['auth', 'control.license'])->name('fiscal.nfce.cancel');
        $this->post('/__legal-fiscal-cancel')->assertOk()->assertSee('cancelamento-permitido');

        Route::post('/__issue-fiscal', fn () => response('emissao-inadmissivel'))
            ->middleware(['auth', 'control.license'])->name('fiscal.nfce.store');
        $this->postJson('/__issue-fiscal')->assertStatus(403)
            ->assertJsonPath('code', 'LUMERON_MODULE_UNAVAILABLE');
    }

    public function test_suspended_subscription_still_allows_read_only_access_to_existing_data(): void
    {
        $this->signedLicense(['products'], 'my-store', 'suspended');
        $this->actingAs($this->admin());
        $this->get('/finance')->assertOk()->assertSee('Modo consulta');
        $this->get('/dashboard')->assertOk()->assertSee('Financeiro');
        $this->postJson('/finance/entries')->assertStatus(423)
            ->assertJsonPath('code', 'LUMERON_LICENSE_READ_ONLY');
    }

    public function test_unsigned_custom_plan_or_invalid_module_fails_closed(): void
    {
        $this->signedLicense(['products','unrecognized'], 'my-store');
        $this->actingAs($this->admin());
        $state = app(CentralLicenseService::class)->refresh();
        $this->assertSame('unverified', $state['status']);
        $this->postJson('/products')->assertStatus(423);
    }

    public function test_first_page_after_login_uses_a_module_included_in_plan(): void
    {
        $this->signedLicense(['products'], 'products-only');
        $this->admin();
        $user = User::create([
            'name' => 'Operadora',
            'username' => 'operator-products',
            'email' => 'operator-products@example.test',
            'password' => 'senha-longa-de-teste',
            'role' => 'operator',
            'permissions' => ['finance','products'],
            'is_active' => true,
        ]);

        $this->assertSame('finance.dashboard', $user->homeRouteName());
        $this->assertSame('products.index', $user->licensedHomeRouteName());
        $this->actingAs($user)->get('/')->assertRedirect(route('products.index'));
        $this->get('/products')->assertOk();
    }

    public function test_existing_api_token_cannot_read_unlicensed_module_data(): void
    {
        $this->signedLicense(['products'], 'professional');
        \App\Models\AppSetting::put('integrations', 'api_enabled', true);
        \App\Models\AppSetting::put('integrations', 'api_token', 'test-token-api-not-for-production', true);

        foreach (['api/lumeron', 'api/nextor'] as $prefix) {
            $this->withToken('test-token-api-not-for-production')
                ->getJson('/'.$prefix.'/products')->assertOk();
            $this->withToken('test-token-api-not-for-production')
                ->getJson('/'.$prefix.'/customers')->assertOk();
            $this->withToken('test-token-api-not-for-production')
                ->getJson('/'.$prefix.'/sales')->assertStatus(403)
                ->assertJsonPath('code', 'LUMERON_MODULE_UNAVAILABLE');
        }
    }

    public function test_disabled_central_license_remains_backwards_compatible(): void
    {
        config()->set('central_license.enabled', false);
        $this->actingAs($this->admin());
        $this->get('/dashboard')->assertOk()->assertSee('Financeiro');
        $this->get('/finance')->assertOk();
    }
}
