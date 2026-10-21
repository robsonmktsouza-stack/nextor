<?php

namespace Tests\Feature;

use App\Models\FinancialCategory;
use App\Models\FinancialEntry;
use App\Models\FinancialRecurrence;
use App\Models\User;
use App\Services\FinancialRecurrenceService;
use App\Services\License\CentralLicenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class CommercialBackgroundAccessTest extends TestCase
{
    use RefreshDatabase;

    private const INSTANCE = 'b6fd7d52-5b56-4562-a622-2d36936b57b9';
    private const CNPJ = '11222333000181';

    private function signedLicense(string $status, array $modules): void
    {
        $secret = str_repeat('a', 96);
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
            'plan' => 'professional',
            'modules' => $modules,
            'expires_at' => today()->addMonth()->toDateString(),
            'checked_at' => now()->toIso8601String(),
        ], JSON_UNESCAPED_SLASHES);
        Http::fake(['*' => Http::response($body, 200, [
            'X-Lumeron-Signature' => hash_hmac('sha256', $body, $secret),
        ])]);
    }

    public function test_expired_cached_license_is_restricted_even_before_cache_refresh(): void
    {
        config()->set('central_license.enabled', true);
        Cache::put('lumeron:central-license:v1', [
            'status' => 'active',
            'modules' => ['finance'],
            'expires_at' => today()->subDay()->toDateString(),
            'source' => 'offline-grace',
        ], 300);

        $this->assertSame('expired', app(CentralLicenseService::class)->state()['status']);
        $this->assertFalse(\App\Support\CommercialBackgroundAccess::allows('finance'));
    }

    public function test_scheduled_recurrences_respect_subscription_status_and_module(): void
    {
        $user = User::create([
            'name' => 'Teste',
            'email' => 'license-background@example.test',
            'password' => 'senha-longa',
        ]);
        $category = FinancialCategory::query()->where('type', 'expense')->firstOrFail();
        $recurrence = FinancialRecurrence::query()->create([
            'type' => 'payable',
            'category_id' => $category->id,
            'created_by' => $user->id,
            'description' => 'Recorrência comercial',
            'amount' => '500.00',
            'frequency' => 'monthly',
            'interval_count' => 1,
            'start_date' => today()->toDateString(),
            'next_date' => today()->toDateString(),
            'is_active' => true,
        ]);
        $service = app(FinancialRecurrenceService::class);

        $this->signedLicense('suspended', ['finance']);
        $this->assertSame(0, $service->generateDue($recurrence));
        $this->signedLicense('active', ['products']);
        $this->assertSame(0, $service->generateDue($recurrence));
        $this->assertDatabaseCount('financial_entries', 0);

        $this->signedLicense('active', ['products', 'finance']);
        $this->assertSame(1, $service->generateDue($recurrence));
        $this->assertDatabaseHas('financial_entries', [
            'recurrence_id' => $recurrence->id,
            'description' => 'Recorrência comercial',
        ]);
    }
}
