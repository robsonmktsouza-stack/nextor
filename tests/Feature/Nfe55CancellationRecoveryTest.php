<?php

namespace Tests\Feature;

use App\Jobs\ProcessNfe55CancellationJob;
use App\Models\CompanySetting;
use App\Models\NfeDraft;
use App\Services\Fiscal\Nfe55CancellationReconciliationService;
use App\Services\Fiscal\Nfe55CancellationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class Nfe55CancellationRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function draft(): NfeDraft
    {
        Storage::fake('local');
        CompanySetting::current()->update(['document' => '12345678000100', 'state' => 'BA']);
        $draft = NfeDraft::query()->create([
            'issue_date' => '2026-10-25', 'status' => 'authorized',
            'environment' => 'homologation', 'series' => 1, 'document_number' => 23,
            'access_key' => '29261012345678000100550010000000231123456780',
            'protocol' => '129260000000001', 'authorized_at' => now(),
            'cancellation_status' => 'uncertain', 'cancellation_attempted_at' => now(),
        ]);
        $draft->update(['xml_path' => 'fiscal/nfe55/'.$draft->id.'/authorized.xml']);
        Storage::disk('local')->put($draft->xml_path, 'original-authorized-document');
        return $draft;
    }

    /** Synthetic protocol fixture exercises reconciliation; it is not SEFAZ homologation. */
    private function receipt(NfeDraft $draft, string $environment = '2', string $event = '110111'): string
    {
        $fields = '<tpAmb>'.$environment.'</tpAmb><chNFe>'.$draft->access_key.'</chNFe>'
            .'<tpEvento>'.$event.'</tpEvento><nSeqEvento>1</nSeqEvento>';
        return '[Consulta]'."\nCStat=101\nXml="
            .'<retConsSitNFe xmlns="http://www.portalfiscal.inf.br/nfe">'
            .'<procEventoNFe><evento><infEvento>'.$fields.'<CNPJ>12345678000100</CNPJ>'
            .'<detEvento><nProt>'.$draft->protocol.'</nProt></detEvento></infEvento></evento>'
            .'<retEvento><infEvento>'.$fields.'<cStat>135</cStat><nProt>129260000000002</nProt>'
            .'</infEvento></retEvento></procEventoNFe></retConsSitNFe>';
    }

    private function storeReceipt(NfeDraft $draft, string $raw): string
    {
        $path = 'fiscal/nfe55/'.$draft->id.'/consult-response.ini';
        Storage::disk('local')->put($path, $raw);
        return $path;
    }

    public function test_matching_queried_event_reconciles_uncertain_cancellation_without_changing_authorized_xml(): void
    {
        $draft = $this->draft();
        $path = $this->storeReceipt($draft, $this->receipt($draft));
        $service = app(Nfe55CancellationReconciliationService::class);
        self::assertTrue($service->reconcile($draft, $path));
        $draft->refresh();
        self::assertSame('cancelled', $draft->status);
        self::assertSame('authorized', $draft->cancellation_status);
        self::assertSame('129260000000002', $draft->cancellation_protocol);
        self::assertSame('129260000000001', $draft->protocol);
        self::assertSame('original-authorized-document', Storage::disk('local')->get($draft->xml_path));
        $cancelledAt = $draft->cancelled_at->toIso8601String();
        $this->travel(1)->days();
        self::assertTrue($service->reconcile($draft, $path));
        self::assertSame($cancelledAt, $draft->fresh()->cancelled_at->toIso8601String());
        $this->travelBack();
    }

    public function test_consultation_status_without_matching_processed_event_cannot_confirm_cancellation(): void
    {
        $draft = $this->draft();
        $service = app(Nfe55CancellationReconciliationService::class);
        foreach ([
            "[Consulta]\nCStat=101\nNProt=129260000000002\nchDFe=".$draft->access_key,
            $this->receipt($draft, '1'),
            $this->receipt($draft, '2', '210210'),
            str_replace('<nProt>'.$draft->protocol.'</nProt>', '<nProt>129260000000099</nProt>', $this->receipt($draft)),
            str_replace($draft->access_key, str_repeat('1', 44), $this->receipt($draft)),
        ] as $raw) {
            self::assertFalse($service->reconcile($draft, $this->storeReceipt($draft, $raw)));
            self::assertSame('authorized', $draft->fresh()->status);
            self::assertSame('uncertain', $draft->fresh()->cancellation_status);
        }
    }

    public function test_preparation_failure_can_be_requested_again_but_external_attempt_never_can(): void
    {
        Bus::fake();
        $draft = $this->draft();
        $draft->update(['cancellation_status' => 'prepared', 'cancellation_attempted_at' => null]);
        $service = app(Nfe55CancellationService::class);
        $service->request($draft, 'Operação comercial não realizada pelo destinatário');
        self::assertSame('pending', $draft->fresh()->cancellation_status);
        Bus::assertDispatchedTimes(ProcessNfe55CancellationJob::class, 1);

        $draft->update(['cancellation_status' => 'uncertain', 'cancellation_attempted_at' => now()]);
        try {
            $service->request($draft, 'Operação comercial não realizada pelo destinatário');
            self::fail('A possibly registered event must never be replayed.');
        } catch (ValidationException) {
            Bus::assertDispatchedTimes(ProcessNfe55CancellationJob::class, 1);
        }
    }

    public function test_local_preparation_failure_does_not_become_an_uncertain_external_event(): void
    {
        $draft = $this->draft();
        $draft->update([
            'cancellation_status' => 'pending', 'cancellation_attempted_at' => null,
            'cancellation_reason' => 'Operação comercial não realizada pelo destinatário',
        ]);
        config(['services.acbr_nfe.library_path' => '/not-installed/acbr-nfe-library']);
        self::assertTrue(app(Nfe55CancellationService::class)->process($draft->id));
        self::assertSame('prepared', $draft->fresh()->cancellation_status);
        self::assertNull($draft->fresh()->cancellation_attempted_at);
        self::assertSame('authorized', $draft->fresh()->status);
    }
}
