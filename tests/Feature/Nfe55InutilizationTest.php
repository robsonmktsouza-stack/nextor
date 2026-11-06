<?php

namespace Tests\Feature;

use App\Jobs\ProcessNfe55InutilizationJob;
use App\Models\AppSetting;
use App\Models\CompanySetting;
use App\Models\Nfe55Inutilization;
use App\Models\NfeDraft;
use App\Models\User;
use App\Services\Fiscal\Nfe55InutilizationService;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\WithConfiguredCompany;
use Tests\TestCase;

final class Nfe55InutilizationTest extends TestCase
{
    use RefreshDatabase, WithConfiguredCompany;

    private function setupRequest(): User
    {
        Bus::fake();
        Storage::fake('local');
        AppSetting::put('fiscal', 'enabled', true);
        AppSetting::put('nfe', 'enabled', true);
        AppSetting::put('nfe', 'environment', 'homologation');
        AppSetting::put('nfe', 'series', 1);
        AppSetting::put('nfe', 'next_number', 40);
        return User::query()->create([
            'name' => 'Responsável fiscal', 'email' => 'inutilization55@example.test',
            'password' => 'secret123', 'role' => 'admin', 'is_active' => true,
        ]);
    }

    private function input(): array
    {
        return [
            'year' => (int) now()->year, 'series' => 1,
            'first_number' => 10, 'last_number' => 15,
            'reason' => 'Quebra de sequência não utilizada na emissão fiscal',
        ];
    }

    private function request(User $user): Nfe55Inutilization
    {
        return app(Nfe55InutilizationService::class)->request($this->input(), $user->id);
    }

    private function response(Nfe55Inutilization $record, string $model = '55', string $environment = '2', ?int $last = null): string
    {
        return "[Inutilizacao]\nCStat=102\nTpAmb=".$environment."\nNProt=129260000000010\nXml="
            .'<retInutNFe xmlns="http://www.portalfiscal.inf.br/nfe"><infInut>'
            .'<tpAmb>'.$environment.'</tpAmb><cStat>102</cStat><nProt>129260000000010</nProt>'
            .'<CNPJ>'.$record->issuer_document.'</CNPJ><ano>'.($record->year % 100).'</ano>'
            .'<mod>'.$model.'</mod><serie>'.$record->series.'</serie><nNFIni>'.$record->first_number.'</nNFIni>'
            .'<nNFFin>'.($last ?? $record->last_number).'</nNFFin></infInut></retInutNFe>';
    }

    public function test_unused_gap_queues_model55_without_advancing_number_sequence(): void
    {
        $user = $this->setupRequest();
        $record = $this->request($user);
        self::assertSame('pending', $record->status);
        self::assertSame('homologation', $record->environment);
        self::assertNull($record->attempted_at);
        self::assertSame(40, (int) AppSetting::value('nfe', 'next_number'));
        Bus::assertDispatched(ProcessNfe55InutilizationJob::class, static fn($job) =>
            $job->inutilizationId === $record->id && $job->connection === 'database' && $job->queue === 'fiscal');
        $this->actingAs($user)->get(route('fiscal.nfe.inutilizations.index'))
            ->assertOk()->assertSee('Reagendar pedido')->assertSee('Homologação');
    }

    public function test_overlap_and_reserved_invoice_numbers_are_refused(): void
    {
        $user = $this->setupRequest();
        $this->request($user);
        $service = app(Nfe55InutilizationService::class);
        try {
            $service->request(array_replace($this->input(), ['first_number' => 15, 'last_number' => 17]), $user->id);
            self::fail('An overlapping cancellation of numbering must be refused.');
        } catch (ValidationException) {
            self::assertSame(1, Nfe55Inutilization::query()->count());
        }
        NfeDraft::query()->create([
            'issue_date' => now()->toDateString(), 'series' => 1,
            'document_number' => 20, 'environment' => 'homologation', 'status' => 'prepared',
        ]);
        try {
            $service->request(array_replace($this->input(), ['first_number' => 19, 'last_number' => 21]), $user->id);
            self::fail('A reserved invoice number must not be inutilized.');
        } catch (ValidationException) {
            self::assertSame(1, Nfe55Inutilization::query()->count());
            Bus::assertDispatchedTimes(ProcessNfe55InutilizationJob::class, 1);
        }
    }

    public function test_invalid_range_and_production_do_not_create_a_fiscal_request(): void
    {
        $user = $this->setupRequest();
        $service = app(Nfe55InutilizationService::class);
        foreach ([['last_number' => 40], ['year' => now()->year + 1], ['series' => 1000], ['series' => 2], ['first_number' => 0], ['reason' => 'Curta']] as $change) {
            try {
                $service->request(array_replace($this->input(), $change), $user->id);
                self::fail('Invalid fiscal range must be refused.');
            } catch (ValidationException) {
                self::assertSame(0, Nfe55Inutilization::query()->count());
            }
        }
        AppSetting::put('nfe', 'environment', 'production');
        try {
            $this->request($user);
            self::fail('Production must remain unavailable.');
        } catch (ValidationException) {
            Bus::assertNotDispatched(ProcessNfe55InutilizationJob::class);
        }
    }

    public function test_only_xml_proof_for_exact_model_environment_issuer_year_series_and_range_authorizes(): void
    {
        $user = $this->setupRequest();
        $record = $this->request($user);
        $record->update(['status' => 'processing', 'attempted_at' => now()]);
        $service = app(Nfe55InutilizationService::class);
        $service->applyResponse($record, $this->response($record));
        self::assertSame('authorized', $record->fresh()->status);
        self::assertSame('129260000000010', $record->fresh()->protocol);
        self::assertStringContainsString('<mod>55</mod>', Storage::disk('local')->get($record->fresh()->xml_path));
        self::assertTrue($service->process($record->id));
        self::assertSame('authorized', $record->fresh()->status);
    }

    public function test_unmatched_or_missing_receipt_never_confirms_a_number_range(): void
    {
        $user = $this->setupRequest();
        $record = $this->request($user);
        $service = app(Nfe55InutilizationService::class);
        foreach ([
            $this->response($record, '65'), $this->response($record, '55', '1'),
            $this->response($record, '55', '2', 16),
            str_replace($record->issuer_document, '98765432000100', $this->response($record)),
            str_replace('<serie>1</serie>', '<serie>2</serie>', $this->response($record)),
            str_replace('<ano>'.($record->year % 100).'</ano>', '<ano>20</ano>', $this->response($record)),
            "[Inutilizacao]\nCStat=102\nTpAmb=2\nNProt=129260000000010\n",
        ] as $response) {
            $record->update(['status' => 'processing', 'attempted_at' => now()]);
            $service->applyResponse($record, $response);
            self::assertSame('uncertain', $record->fresh()->status);
            self::assertNull($record->fresh()->protocol);
        }
    }

    public function test_confirmed_inutilization_is_preserved_when_local_xml_storage_fails(): void
    {
        $user = $this->setupRequest();
        $record = $this->request($user);
        $record->update(['status' => 'processing', 'attempted_at' => now()]);
        $response = $this->response($record);
        $filesystem = \Mockery::mock(\Illuminate\Contracts\Filesystem\Filesystem::class);
        $filesystem->shouldReceive('put')->once()
            ->with('fiscal/nfe55/inutilizations/'.$record->id.'/sefaz-response.ini', $response)->andReturn(true);
        $filesystem->shouldReceive('put')->once()
            ->with('fiscal/nfe55/inutilizations/'.$record->id.'/authorized.xml', \Mockery::type('string'))
            ->andThrow(new \RuntimeException('Synthetic storage failure'));
        Storage::shouldReceive('disk')->with('local')->andReturn($filesystem);
        app(Nfe55InutilizationService::class)->applyResponse($record, $response);
        self::assertSame('authorized', $record->fresh()->status);
        self::assertSame('129260000000010', $record->fresh()->protocol);
        self::assertNull($record->fresh()->xml_path);
        self::assertStringContainsString('recuperação do XML pendente', $record->fresh()->error_message);
    }

    public function test_attempted_inutilization_is_not_sent_again_and_busy_lock_releases_job(): void
    {
        $user = $this->setupRequest();
        $record = $this->request($user);
        $record->update(['attempted_at' => now()]);
        $service = app(Nfe55InutilizationService::class);
        self::assertTrue($service->process($record->id));
        self::assertSame('uncertain', $record->fresh()->status);
        $lock = Cache::lock('nfe55-inutilization:'.$record->id, 300);
        self::assertTrue($lock->get());
        try {
            $queueJob = \Mockery::mock(Job::class);
            $queueJob->shouldReceive('release')->once()->with(10);
            $job = new ProcessNfe55InutilizationJob($record->id);
            $job->setJob($queueJob);
            $job->handle($service);
        } finally {
            $lock->release();
        }
    }

    public function test_controller_requires_password_and_explicit_unused_confirmation(): void
    {
        $user = $this->setupRequest();
        $payload = $this->input() + ['current_password' => 'incorrect', 'confirmed_unused' => '1'];
        $this->actingAs($user)->post(route('fiscal.nfe.inutilizations.store'), $payload)
            ->assertSessionHasErrors('current_password');
        $payload['current_password'] = 'secret123';
        unset($payload['confirmed_unused']);
        $this->post(route('fiscal.nfe.inutilizations.store'), $payload)->assertSessionHasErrors('confirmed_unused');
        self::assertSame(0, Nfe55Inutilization::query()->count());
    }

    public function test_retry_keeps_original_request_and_refuses_any_external_attempt(): void
    {
        $user = $this->setupRequest();
        $record = $this->request($user);
        $record->update(['status' => 'uncertain']);
        $this->actingAs($user)->post(route('fiscal.nfe.inutilizations.retry', $record), [
            'current_password' => 'secret123', 'confirmed_unused' => '1',
        ])->assertRedirect(route('fiscal.nfe.inutilizations.index'));
        self::assertSame(1, Nfe55Inutilization::query()->count());
        self::assertSame('pending', $record->fresh()->status);
        Bus::assertDispatchedTimes(ProcessNfe55InutilizationJob::class, 2);

        $record->refresh()->update(['status' => 'uncertain', 'attempted_at' => now()]);
        $this->post(route('fiscal.nfe.inutilizations.retry', $record), [
            'current_password' => 'secret123', 'confirmed_unused' => '1',
        ])->assertSessionHasErrors('first_number');
        Bus::assertDispatchedTimes(ProcessNfe55InutilizationJob::class, 2);
        self::assertSame('uncertain', $record->fresh()->status);
    }

    public function test_fiscal_operator_without_administrative_permission_cannot_request_or_retry(): void
    {
        $admin = $this->setupRequest();
        $record = $this->request($admin);
        $operator = User::query()->create([
            'name' => 'Operador fiscal', 'email' => 'inutilization-operator@example.test',
            'password' => 'secret123', 'role' => 'operator', 'is_active' => true, 'permissions' => ['fiscal'],
        ]);
        $payload = $this->input() + ['current_password' => 'secret123', 'confirmed_unused' => '1'];
        $this->actingAs($operator)->post(route('fiscal.nfe.inutilizations.store'), $payload)->assertForbidden();
        $this->post(route('fiscal.nfe.inutilizations.retry', $record), $payload)->assertForbidden();
        Bus::assertDispatchedTimes(ProcessNfe55InutilizationJob::class, 1);
    }

    public function test_preparation_failure_never_claims_an_external_attempt_and_can_be_rescheduled(): void
    {
        $user = $this->setupRequest();
        $record = $this->request($user);
        config(['services.acbr_nfe.library_path' => '/not-installed/acbr-nfe-library']);
        $service = app(Nfe55InutilizationService::class);
        self::assertTrue($service->process($record->id));
        self::assertSame('prepared', $record->fresh()->status);
        self::assertNull($record->fresh()->attempted_at);
        $service->retry($record->fresh());
        self::assertSame('pending', $record->fresh()->status);
        Bus::assertDispatchedTimes(ProcessNfe55InutilizationJob::class, 2);
    }
}
