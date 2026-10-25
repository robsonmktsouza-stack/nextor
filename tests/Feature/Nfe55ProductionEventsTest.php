<?php

namespace Tests\Feature;

use App\Jobs\ProcessNfe55CancellationJob;
use App\Jobs\ProcessNfe55InutilizationJob;
use App\Models\AppSetting;
use App\Models\CompanySetting;
use App\Models\Nfe55Inutilization;
use App\Models\NfeDraft;
use App\Models\User;
use App\Services\Fiscal\Nfe55CancellationService;
use App\Services\Fiscal\Nfe55InutilizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\WithConfiguredCompany;
use Tests\TestCase;

/** Local event fixtures exercise gates and response matching, never SEFAZ. */
final class Nfe55ProductionEventsTest extends TestCase
{
    use RefreshDatabase, WithConfiguredCompany;

    private function configureProduction(): User
    {
        Bus::fake();
        Storage::fake('local');
        AppSetting::put('fiscal', 'enabled', false);
        AppSetting::put('nfe', 'enabled', false);
        AppSetting::put('nfe', 'production_enabled', true);
        AppSetting::put('nfe', 'environment', 'production');
        AppSetting::put('nfe', 'series', 1);
        AppSetting::put('nfe', 'next_number', 20);
        AppSetting::put('nfe', 'production_series', 2);
        AppSetting::put('nfe', 'production_next_number', 80);
        return User::query()->create([
            'name' => 'Responsável fiscal', 'email' => 'production-events@example.test',
            'password' => 'secret123', 'role' => 'admin', 'is_active' => true,
        ]);
    }

    private function authorizedDraft(): NfeDraft
    {
        $issuer = preg_replace('/\D/', '', (string) CompanySetting::current()->document);
        return NfeDraft::query()->create([
            'status' => 'authorized', 'environment' => 'production', 'series' => 9,
            'document_number' => 42, 'issue_date' => now()->toDateString(),
            'access_key' => '292610'.$issuer.'55'.str_repeat('0', 22),
            'protocol' => '123456789012345', 'authorized_at' => now(),
        ]);
    }

    private function input(): array
    {
        return [
            'year' => (int) now()->year, 'series' => 2,
            'first_number' => 35, 'last_number' => 40,
            'reason' => 'Quebra de sequência não utilizada na emissão fiscal',
        ];
    }

    private function assertRefused(callable $request): void
    {
        try {
            $request();
            self::fail('The event must be refused before any external attempt.');
        } catch (ValidationException) {
            self::assertTrue(true);
        }
    }

    public function test_production_cancellation_can_be_queued_with_emission_disabled_for_an_old_series(): void
    {
        $this->configureProduction();
        $draft = $this->authorizedDraft();
        $service = app(Nfe55CancellationService::class);
        $service->request($draft, $this->input()['reason']);
        self::assertSame('pending', $draft->fresh()->cancellation_status);
        self::assertNull($draft->fresh()->cancellation_attempted_at);
        $this->assertRefused(fn() => $service->request($draft, $this->input()['reason']));
        Bus::assertDispatchedTimes(ProcessNfe55CancellationJob::class, 1);
    }

    public function test_cancellation_refuses_invalid_or_unreleased_environment_before_queueing(): void
    {
        $this->configureProduction();
        $draft = $this->authorizedDraft();
        $service = app(Nfe55CancellationService::class);
        AppSetting::put('nfe', 'production_enabled', false);
        $this->assertRefused(fn() => $service->request($draft, $this->input()['reason']));
        $draft->update(['environment' => 'unknown']);
        $this->assertRefused(fn() => $service->request($draft, $this->input()['reason']));
        Bus::assertNotDispatched(ProcessNfe55CancellationJob::class);
        self::assertNull($draft->fresh()->cancellation_requested_at);
    }

    public function test_cancellation_worker_rechecks_production_release_before_recording_an_attempt(): void
    {
        $this->configureProduction();
        $draft = $this->authorizedDraft();
        $service = app(Nfe55CancellationService::class);
        $service->request($draft, $this->input()['reason']);
        AppSetting::put('nfe', 'production_enabled', false);
        self::assertTrue($service->process($draft->id));
        self::assertSame('prepared', $draft->fresh()->cancellation_status);
        self::assertNull($draft->fresh()->cancellation_attempted_at);
        self::assertSame('authorized', $draft->fresh()->status);
    }

    public function test_production_inutilization_uses_its_own_sequence_without_enabling_new_emission(): void
    {
        $user = $this->configureProduction();
        $record = app(Nfe55InutilizationService::class)->request($this->input(), $user->id);
        self::assertSame('production', $record->environment);
        self::assertSame(2, $record->series);
        self::assertSame(80, AppSetting::value('nfe', 'production_next_number'));
        self::assertSame(20, AppSetting::value('nfe', 'next_number'));
        Bus::assertDispatchedTimes(ProcessNfe55InutilizationJob::class, 1);
    }

    public function test_production_inutilization_never_falls_back_to_homologation_settings(): void
    {
        $user = $this->configureProduction();
        $service = app(Nfe55InutilizationService::class);
        AppSetting::query()->where('group', 'nfe')->where('key', 'production_next_number')->delete();
        $this->assertRefused(fn() => $service->request($this->input(), $user->id));
        AppSetting::put('nfe', 'production_next_number', 80);
        AppSetting::query()->where('group', 'nfe')->where('key', 'production_series')->delete();
        $this->assertRefused(fn() => $service->request($this->input(), $user->id));
        self::assertSame(0, Nfe55Inutilization::query()->count());
        Bus::assertNotDispatched(ProcessNfe55InutilizationJob::class);
    }

    public function test_inutilization_retry_keeps_persisted_environment_and_never_retries_an_external_attempt(): void
    {
        $user = $this->configureProduction();
        $service = app(Nfe55InutilizationService::class);
        $record = $service->request($this->input(), $user->id);
        $record->update(['status' => 'prepared']);
        AppSetting::put('nfe', 'environment', 'homologation');
        $service->retry($record);
        self::assertSame('production', $record->fresh()->environment);
        self::assertSame('pending', $record->fresh()->status);
        $record->refresh()->update(['status' => 'uncertain', 'attempted_at' => now()]);
        $this->assertRefused(fn() => $service->retry($record));
        Bus::assertDispatchedTimes(ProcessNfe55InutilizationJob::class, 2);
    }

    public function test_inutilization_rechecks_production_release_in_request_retry_and_worker(): void
    {
        $user = $this->configureProduction();
        $service = app(Nfe55InutilizationService::class);
        $record = $service->request($this->input(), $user->id);
        $record->update(['status' => 'prepared']);
        AppSetting::put('nfe', 'production_enabled', false);
        $this->assertRefused(fn() => $service->request($this->input(), $user->id));
        $this->assertRefused(fn() => $service->retry($record));
        $record->update(['status' => 'pending']);
        self::assertTrue($service->process($record->id));
        self::assertSame('prepared', $record->fresh()->status);
        self::assertNull($record->fresh()->attempted_at);
        Bus::assertDispatchedTimes(ProcessNfe55InutilizationJob::class, 1);
    }

    public function test_production_inutilization_accepts_only_matching_production_xml_proof(): void
    {
        $user = $this->configureProduction();
        $service = app(Nfe55InutilizationService::class);
        $record = $service->request($this->input(), $user->id);
        // Synthetic local parser fixtures are never sent to a fiscal service.
        foreach (['2', '1'] as $environment) {
            $record->update(['status' => 'processing', 'attempted_at' => now()]);
            $raw = "[Inutilizacao]\nCStat=102\nTpAmb={$environment}\nNProt=129260000000010\nXml="
                .'<retInutNFe xmlns="http://www.portalfiscal.inf.br/nfe"><infInut>'
                .'<tpAmb>'.$environment.'</tpAmb><cStat>102</cStat><nProt>129260000000010</nProt>'
                .'<CNPJ>'.$record->issuer_document.'</CNPJ><ano>'.($record->year % 100).'</ano>'
                .'<mod>55</mod><serie>2</serie><nNFIni>35</nNFIni><nNFFin>40</nNFFin>'
                .'</infInut></retInutNFe>';
            $service->applyResponse($record, $raw);
            self::assertSame($environment === '1' ? 'authorized' : 'uncertain', $record->fresh()->status);
            if ($environment === '2') self::assertNull($record->fresh()->protocol);
        }
        self::assertSame('129260000000010', $record->fresh()->protocol);
        self::assertSame(80, AppSetting::value('nfe', 'production_next_number'));
    }
}
