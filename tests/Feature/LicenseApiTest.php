<?php

namespace Tests\Feature;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LicenseApiTest extends TestCase
{
    use RefreshDatabase;

    private function company(): Company
    {
        return Company::create([
            'instance_id' => 'b6fd7d52-5b56-4562-a622-2d36936b57b9',
            'legal_name' => 'EMPRESA DE TESTE LTDA',
            'cnpj' => '11222333000181',
            'email' => 'cliente@example.test',
            'subdomain' => 'empresa-api',
            'plan' => 'standard',
            'license_status' => 'active',
            'expires_at' => today()->addMonth()->toDateString(),
            'modules' => ['pdv', 'fiscal'],
            'license_secret' => bin2hex(random_bytes(48)),
        ]);
    }

    private function check(Company $company, ?string $secret = null, ?string $nonce = null, ?string $cnpj = null)
    {
        $json = json_encode(['cnpj' => $cnpj ?? $company->cnpj, 'version' => 'v1.0.0']);
        $timestamp = (string) time();
        $nonce ??= bin2hex(random_bytes(16));
        $canonical = "POST\n/api/license/check\n{$timestamp}\n{$nonce}\n".hash('sha256', $json);
        $signature = hash_hmac('sha256', $canonical, $secret ?? $company->license_secret);
        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_LUMERON_INSTANCE' => $company->instance_id,
            'HTTP_X_LUMERON_TIMESTAMP' => $timestamp,
            'HTTP_X_LUMERON_NONCE' => $nonce,
            'HTTP_X_LUMERON_SIGNATURE' => $signature,
        ];
        return $this->call('POST', '/api/license/check', [], [], [], $headers, $json);
    }

    public function test_unsigned_calls_are_rejected(): void
    {
        $this->postJson('/api/license/check', ['cnpj' => '11222333000181'])->assertUnauthorized();
    }

    public function test_signed_check_returns_real_status_and_signed_response(): void
    {
        $company = $this->company();
        $response = $this->check($company);
        $response->assertOk()->assertJsonPath('status', 'active')
            ->assertJsonPath('modules.0', 'pdv')->assertJsonPath('modules.1', 'products')
            ->assertJsonPath('cnpj', $company->cnpj);
        $raw = $response->getContent();
        $this->assertSame(
            hash_hmac('sha256', $raw, $company->license_secret),
            $response->headers->get('X-Lumeron-Signature')
        );
        $this->assertNotNull($company->fresh()->last_seen_at);
    }

    public function test_signed_api_delivers_only_enabled_reminder_thresholds(): void
    {
        $company = $this->company();
        $this->check($company)->assertOk()->assertJsonPath('notice_days', null);

        \App\Models\AutomationRule::query()->where('code', 'expiry_soon')
            ->update(['enabled' => true, 'days' => 7]);
        $response = $this->check($company)->assertOk()->assertJsonPath('notice_days', 7);
        $signedJson = $response->getContent();
        $this->assertSame(
            hash_hmac('sha256', $signedJson, $company->license_secret),
            $response->headers->get('X-Lumeron-Signature')
        );

        \App\Models\AutomationRule::query()->create([
            'code' => 'custom_'.strtolower(\Illuminate\Support\Str::random(16)),
            'name' => 'Antecedência estendida',
            'description' => 'Aviso antecipado na instalação',
            'kind' => 'expiry_soon',
            'enabled' => true,
            'days' => 15,
            'notify_client_email' => false,
        ]);
        $this->check($company)->assertOk()->assertJsonPath('notice_days', 15);
    }

    public function test_replay_and_wrong_signature_or_cnpj_are_rejected(): void
    {
        $company = $this->company();
        $nonce = bin2hex(random_bytes(16));
        $this->check($company, nonce: $nonce)->assertOk();
        $this->check($company, nonce: $nonce)->assertStatus(409);
        $this->check($company, secret: 'invalid-secret')->assertUnauthorized();
        $this->check($company, cnpj: '00000000000000')->assertStatus(422);
    }

    public function test_api_entitlements_come_from_editable_plan_not_obsolete_company_snapshot(): void
    {
        $company = $this->company();
        $this->assertSame(['pdv', 'fiscal'], $company->modules);
        $plan = \App\Models\Plan::where('code', 'standard')->firstOrFail();
        $plan->update(['modules' => ['finance', 'services']]);

        $this->check($company)->assertOk()
            ->assertJsonPath('plan', 'standard')
            ->assertJsonPath('modules', ['finance', 'services']);
        $this->assertSame(['pdv', 'fiscal'], $company->fresh()->modules);
    }

    public function test_suspended_and_expired_status_are_visible(): void
    {
        $company = $this->company();
        $company->update(['license_status' => 'suspended']);
        $this->check($company)->assertJsonPath('status', 'suspended');
        $company->update(['license_status' => 'active', 'expires_at' => today()->subDay()->toDateString()]);
        $this->check($company)->assertJsonPath('status', 'expired');
    }
}
