<?php

namespace Tests\Feature;

use App\Jobs\ProcessNfe55CancellationJob;
use App\Jobs\ProcessNfe55Job;
use App\Models\AppSetting;
use App\Models\CompanySetting;
use App\Models\NfeDraft;
use App\Models\Nfe55Inutilization;
use App\Models\OperationNature;
use App\Services\Fiscal\Nfe55CancellationService;
use App\Services\Fiscal\Nfe55DocumentSnapshot;
use App\Services\Fiscal\Nfe55EmissionService;
use App\Services\Fiscal\Nfe55NumberReservationService;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class Nfe55ConcurrencySafetyTest extends TestCase
{
    use RefreshDatabase;

    private function draft(): NfeDraft
    {
        CompanySetting::current()->update(['document' => '12345678000100', 'state' => 'BA', 'city_ibge_code' => '2927408']);
        $nature = OperationNature::query()->create(['name' => 'VENDA', 'operation_type' => 'outbound']);
        $draft = NfeDraft::query()->create([
            'operation_nature_id' => $nature->id, 'issue_date' => '2026-10-25',
            'issue_time' => '14:00',
            'operation_type' => 'outbound', 'destination' => 'internal', 'purpose' => 'normal',
            'environment' => 'homologation', 'series' => 1, 'validated_at' => now(),
        ]);
        $draft->items()->create([
            'item_number' => 1, 'product_name' => 'Produto de teste',
            'quantity' => '1.0000', 'unit_price' => '100.0000',
            'tax_data' => ['icms_csosn' => '102'],
        ]);
        return $draft->fresh(['items', 'operationNature']);
    }

    private function fingerprint(NfeDraft $draft): string
    {
        return app(Nfe55DocumentSnapshot::class)->fingerprint($draft, CompanySetting::current());
    }

    private function assertReservationRefused(NfeDraft $draft, string $fingerprint): void
    {
        try {
            app(Nfe55NumberReservationService::class)->reserve($draft->id, $fingerprint);
            self::fail('A changed or reserved document must not receive another fiscal number.');
        } catch (ValidationException) {
            self::assertSame(23, (int) AppSetting::value('nfe', 'next_number'));
        }
    }

    public function test_checked_snapshot_reserves_once_without_revalidating_a_stale_model(): void
    {
        $draft = $this->draft();
        AppSetting::put('nfe', 'next_number', 23);
        $fingerprint = $this->fingerprint($draft);
        $reserved = app(Nfe55NumberReservationService::class)->reserve($draft->id, $fingerprint);
        self::assertSame('queued', $reserved->status);
        self::assertSame(23, (int) $reserved->document_number);
        self::assertGreaterThan(0, $reserved->numeric_code);
        self::assertSame(24, (int) AppSetting::value('nfe', 'next_number'));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($reserved->custom_fields, '_reserved_fiscal_fingerprint'));
        self::assertArrayNotHasKey('certificate_password', $reserved->emitter_snapshot);
        self::assertArrayNotHasKey('certificate_path', $reserved->emitter_snapshot);
        self::assertSame('12345678000100', $reserved->emitter_snapshot['document']);
        self::assertSame(data_get($reserved->custom_fields, '_reserved_fiscal_fingerprint'),
            app(Nfe55DocumentSnapshot::class)->materialFingerprint($reserved->fresh(['items', 'operationNature']), CompanySetting::current()));

        try {
            app(Nfe55NumberReservationService::class)->reserve($draft->id, $fingerprint);
            self::fail('A second submission must not reserve a new number.');
        } catch (ValidationException) {
            self::assertSame(24, (int) AppSetting::value('nfe', 'next_number'));
            self::assertSame(23, (int) $draft->fresh()->document_number);
        }
    }

    public function test_edit_between_native_check_and_reservation_invalidates_the_checked_document(): void
    {
        $draft = $this->draft();
        AppSetting::put('nfe', 'next_number', 23);
        $fingerprint = $this->fingerprint($draft);
        $draft->fresh()->update(['validated_at' => null, 'additional_info' => 'Texto alterado']);
        $this->assertReservationRefused($draft, $fingerprint);
        self::assertNull($draft->fresh()->document_number);
    }

    public function test_inconsistent_draft_with_existing_fiscal_identity_cannot_reserve_another_number(): void
    {
        $draft = $this->draft();
        AppSetting::put('nfe', 'next_number', 23);
        $draft->update(['access_key' => '29261012345678000100550010000000231123456780']);
        $this->assertReservationRefused($draft, $this->fingerprint($draft->fresh(['items', 'operationNature'])));
        self::assertNull($draft->fresh()->document_number);
    }

    public function test_changed_item_is_detected_even_when_header_timestamp_and_validation_are_unchanged(): void
    {
        $draft = $this->draft();
        AppSetting::put('nfe', 'next_number', 23);
        $fingerprint = $this->fingerprint($draft);
        $draft->items()->first()->update(['tax_data' => ['icms_csosn' => '500']]);
        $this->assertReservationRefused($draft, $fingerprint);
        self::assertNull($draft->fresh()->document_number);
    }

    public function test_json_object_key_order_does_not_invalidate_a_checked_snapshot(): void
    {
        $draft = $this->draft();
        $item = $draft->items()->first();
        $item->update(['tax_data' => ['icms_csosn' => '102', 'pis_cst' => '49']]);
        $draft = $draft->fresh(['items', 'operationNature']);
        $fingerprint = $this->fingerprint($draft);
        DB::table('nfe_draft_items')->where('id', $item->id)
            ->update(['tax_data' => '{"pis_cst":"49","icms_csosn":"102"}']);
        self::assertSame($fingerprint, $this->fingerprint($draft->fresh(['items', 'operationNature'])));
    }

    public function test_changed_operation_nature_or_issuer_requires_a_new_check(): void
    {
        $draft = $this->draft();
        AppSetting::put('nfe', 'next_number', 23);
        $fingerprint = $this->fingerprint($draft);
        $draft->operationNature->update(['name' => 'REMESSA']);
        $this->assertReservationRefused($draft, $fingerprint);

        $fingerprint = $this->fingerprint($draft->fresh(['items', 'operationNature']));
        CompanySetting::current()->update(['state' => 'SP']);
        $this->assertReservationRefused($draft, $fingerprint);
        self::assertNull($draft->fresh()->document_number);
    }

    public function test_number_overflow_rolls_back_the_reservation_and_counter(): void
    {
        $draft = $this->draft();
        AppSetting::put('nfe', 'next_number', 1000000000);
        try {
            app(Nfe55NumberReservationService::class)->reserve($draft->id, $this->fingerprint($draft));
            self::fail('An invalid NF-e number must not be committed.');
        } catch (ValidationException) {
            self::assertSame(1000000000, (int) AppSetting::value('nfe', 'next_number'));
            self::assertNull($draft->fresh()->document_number);
            self::assertSame('draft', $draft->fresh()->status);
        }
    }

    public function test_reset_sequence_cannot_allocate_a_number_already_requested_for_inutilization(): void
    {
        $draft = $this->draft();
        AppSetting::put('nfe', 'next_number', 23);
        Nfe55Inutilization::query()->create([
            'issuer_document' => '12345678000100', 'environment' => 'homologation',
            'year' => 2026, 'series' => 1, 'first_number' => 23, 'last_number' => 24,
            'reason' => 'Faixa solicitada anteriormente e ainda não confirmada', 'status' => 'uncertain',
        ]);
        $this->assertReservationRefused($draft, $this->fingerprint($draft));
        self::assertNull($draft->fresh()->document_number);
    }

    public function test_emission_job_is_released_when_document_lock_is_occupied(): void
    {
        $draft = $this->draft();
        $draft->update(['status' => 'queued', 'document_number' => 23]);
        $lock = Cache::lock('nfe-55:'.$draft->id, 300);
        self::assertTrue($lock->get());
        try {
            $queueJob = \Mockery::mock(Job::class);
            $queueJob->shouldReceive('release')->once()->with(10);
            $job = new ProcessNfe55Job($draft->id);
            $job->setJob($queueJob);
            $job->handle(app(Nfe55EmissionService::class));
            self::assertSame('queued', $draft->fresh()->status);
            self::assertNull($draft->fresh()->transmission_attempted_at);
        } finally {
            $lock->release();
        }
    }

    public function test_issuer_change_after_reservation_stops_worker_before_external_attempt(): void
    {
        $draft = $this->draft();
        $reserved = app(Nfe55NumberReservationService::class)->reserve($draft->id, $this->fingerprint($draft));
        CompanySetting::current()->update(['state' => 'SP']);
        self::assertTrue(app(Nfe55EmissionService::class)->process($reserved->id));
        self::assertSame('prepared', $reserved->fresh()->status);
        self::assertNull($reserved->fresh()->transmission_attempted_at);
        self::assertNull($reserved->fresh()->xml_path);
        self::assertSame('BA', $reserved->fresh()->emitter_snapshot['state']);
    }

    public function test_requeued_document_with_previous_external_attempt_is_never_transmitted_again(): void
    {
        $draft = $this->draft();
        $draft->update([
            'status' => 'queued', 'document_number' => 23, 'numeric_code' => 12345678,
            'transmission_attempted_at' => now()->subMinutes(20),
        ]);
        $attemptedAt = $draft->transmission_attempted_at->toIso8601String();
        self::assertTrue(app(Nfe55EmissionService::class)->process($draft->id));
        self::assertSame('pending', $draft->fresh()->status);
        self::assertSame($attemptedAt, $draft->fresh()->transmission_attempted_at->toIso8601String());
        self::assertNull($draft->fresh()->xml_path);
    }

    public function test_cancellation_job_is_released_when_document_lock_is_occupied(): void
    {
        $draft = $this->draft();
        $draft->update(['status' => 'authorized', 'cancellation_status' => 'pending']);
        $lock = Cache::lock('nfe-55:'.$draft->id, 300);
        self::assertTrue($lock->get());
        try {
            $queueJob = \Mockery::mock(Job::class);
            $queueJob->shouldReceive('release')->once()->with(10);
            $job = new ProcessNfe55CancellationJob($draft->id);
            $job->setJob($queueJob);
            $job->handle(app(Nfe55CancellationService::class));
            self::assertSame('pending', $draft->fresh()->cancellation_status);
            self::assertNull($draft->fresh()->cancellation_attempted_at);
        } finally {
            $lock->release();
        }
    }

    public function test_signed_document_correspondence_guard_checks_operation_items_and_total(): void
    {
        $draft = $this->draft();
        $draft->update(['document_number' => 23, 'numeric_code' => 12345678, 'totals' => ['total' => '100.00']]);
        $key = '29261012345678000100550010000000231123456780';
        // This fixture tests correspondence only. Signature verification remains
        // a separate mandatory native ACBr operation; this is not a signed NF-e.
        $xml = '<NFe xmlns="http://www.portalfiscal.inf.br/nfe"><infNFe Id="NFe'.$key.'">'
            .'<ide><cUF>29</cUF><dhEmi>2026-10-25T14:00:00-03:00</dhEmi><mod>55</mod><serie>1</serie><nNF>23</nNF><cNF>12345678</cNF>'
            .'<tpAmb>2</tpAmb><tpEmis>1</tpEmis><tpNF>1</tpNF><idDest>1</idDest><finNFe>1</finNFe></ide>'
            .'<emit><CNPJ>12345678000100</CNPJ></emit><det nItem="1"><prod/></det>'
            .'<total><ICMSTot><vNF>100.00</vNF></ICMSTot></total></infNFe>'
            .'<Signature xmlns="http://www.w3.org/2000/09/xmldsig#"/></NFe>';
        $service = app(Nfe55EmissionService::class);
        self::assertSame($key, $service->verifySignedXml($xml, $draft, CompanySetting::current()));
        foreach ([
            ['<tpNF>1</tpNF>', '<tpNF>0</tpNF>'], ['<idDest>1</idDest>', '<idDest>2</idDest>'],
            ['<finNFe>1</finNFe>', '<finNFe>4</finNFe>'], ['<vNF>100.00</vNF>', '<vNF>99.99</vNF>'],
            ['<cUF>29</cUF>', '<cUF>35</cUF>'], ['2026-10-25T14:00:00-03:00', '2026-10-26T14:00:00-03:00'],
            ['<det nItem="1"><prod/></det>', ''],
        ] as [$before, $after]) {
            try {
                $service->verifySignedXml(str_replace($before, $after, $xml), $draft, CompanySetting::current());
                self::fail('A different operation, item count or total must not reach transmission.');
            } catch (\RuntimeException) {
                self::assertNull($draft->fresh()->transmission_attempted_at);
            }
        }
    }

    public function test_protocol_digest_must_match_the_signature_reference_for_this_exact_key(): void
    {
        $key = '29261012345678000100550010000000231123456780';
        $digest = base64_encode(hash('sha1', 'fixture', true));
        $reference = '<Reference URI="#NFe'.$key.'"><DigestValue>'.$digest.'</DigestValue></Reference>';
        $xml = '<NFe xmlns="http://www.portalfiscal.inf.br/nfe"><Signature xmlns="http://www.w3.org/2000/09/xmldsig#">'
            .'<SignedInfo>'.$reference.'</SignedInfo></Signature></NFe>';
        $service = app(Nfe55EmissionService::class);
        self::assertTrue($service->protocolDigestMatches($xml, $key, $digest));
        self::assertFalse($service->protocolDigestMatches($xml, $key, base64_encode(hash('sha1', 'different', true))));
        self::assertFalse($service->protocolDigestMatches($xml, str_repeat('1', 44), $digest));
        self::assertFalse($service->protocolDigestMatches(str_replace($reference, $reference.$reference, $xml), $key, $digest));
    }

    public function test_authorization_time_comes_from_protocol_and_is_not_reset_by_a_later_consultation(): void
    {
        Storage::fake('local');
        $draft = $this->draft();
        $key = '29261012345678000100550010000000231123456780';
        $digest = base64_encode(hash('sha1', 'fixture', true));
        $draft->update(['status' => 'pending', 'access_key' => $key]);
        $signed = '<NFe xmlns="http://www.portalfiscal.inf.br/nfe"><infNFe Id="NFe'.$key.'"/>'
            .'<Signature xmlns="http://www.w3.org/2000/09/xmldsig#"><SignedInfo>'
            .'<Reference URI="#NFe'.$key.'"><DigestValue>'.$digest.'</DigestValue></Reference>'
            .'</SignedInfo></Signature></NFe>';
        $response = [
            'individual' => true, 'cstat' => '100', 'key' => $key, 'protocol' => '129260000000001',
            'environment' => '2', 'application' => 'fixture', 'digest' => $digest,
            'received_at' => '2026-10-25T14:00:00-03:00', 'reason' => 'Fixture de protocolo',
        ];
        $method = new \ReflectionMethod(Nfe55EmissionService::class, 'persistAuthorization');
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-25T18:00:00Z'));
        $method->invoke(app(Nfe55EmissionService::class), $draft, $signed, $response);
        self::assertSame('2026-10-25 17:00:00', $draft->fresh()->authorized_at->utc()->format('Y-m-d H:i:s'));
        $this->travel(2)->days();
        $method->invoke(app(Nfe55EmissionService::class), $draft->fresh(), $signed, $response);
        self::assertSame('2026-10-25 17:00:00', $draft->fresh()->authorized_at->utc()->format('Y-m-d H:i:s'));
        $this->travelBack();
    }

    public function test_model55_protocol_time_uses_explicit_offset_or_configured_timezone_without_bahia_assumption(): void
    {
        $service = app(Nfe55EmissionService::class);
        self::assertSame('2026-10-25T14:00:00-04:00', $service->normalizeReceivedAt('25/10/2026 14:00:00', 'America/Manaus'));
        self::assertSame('2026-10-25T14:00:00-03:00', $service->normalizeReceivedAt('2026-10-25T14:00:00-03:00', 'America/Manaus'));
        foreach (['2026-02-30T14:00:00-03:00', '30/02/2026 14:00:00', '2026-10-25T14:00:00+25:00'] as $invalid) {
            try {
                $service->normalizeReceivedAt($invalid, 'America/Manaus');
                self::fail('An invalid protocol timestamp must not be normalized into a real date.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('Data de autorização SEFAZ inválida', $error->getMessage());
            }
        }
    }
}
