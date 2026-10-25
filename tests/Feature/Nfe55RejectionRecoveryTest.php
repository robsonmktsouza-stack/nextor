<?php

namespace Tests\Feature;

use App\Jobs\ProcessNfe55Job;
use App\Models\AppSetting;
use App\Models\CompanySetting;
use App\Models\NfeDraft;
use App\Models\OperationNature;
use App\Services\Fiscal\NFCeSefazResponseParser;
use App\Services\Fiscal\Nfe55DocumentSnapshot;
use App\Services\Fiscal\Nfe55EmissionService;
use App\Services\Fiscal\Nfe55RejectionRecoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class Nfe55RejectionRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function draft(): NfeDraft
    {
        Storage::fake('local');
        CompanySetting::current()->update(['document' => '12345678000100', 'state' => 'BA', 'city_ibge_code' => '2927408']);
        $number = 23 + NfeDraft::query()->count();
        $note = NfeDraft::query()->create([
            'status' => 'pending', 'issue_date' => '2026-10-25', 'issue_time' => '14:00',
            'operation_type' => 'outbound', 'destination' => 'internal', 'purpose' => 'normal',
            'environment' => 'homologation', 'series' => 1, 'document_number' => $number,
            'numeric_code' => 12345678, 'totals' => ['total' => '100.00'],
            'access_key' => sprintf('2926101234567800010055001%09d1123456780', $number),
            'transmission_attempted_at' => now(),
        ]);
        $note->update(['xml_path' => 'fiscal/nfe55/'.$note->id.'/signed.xml', 'response_path' => 'fiscal/nfe55/'.$note->id.'/sefaz-response.ini']);
        $note->refresh();
        $custom = ['_reserved_fiscal_fingerprint' => app(Nfe55DocumentSnapshot::class)->materialFingerprint($note, CompanySetting::current())];
        $note->update(['custom_fields' => $custom]);
        // Correspondence fixture only; not a cryptographically signed NF-e.
        Storage::disk('local')->put($note->xml_path, '<NFe xmlns="http://www.portalfiscal.inf.br/nfe"><infNFe Id="NFe'.$note->access_key.'">'
            .'<ide><cUF>29</cUF><dhEmi>2026-10-25T14:00:00-03:00</dhEmi><mod>55</mod><serie>1</serie><nNF>'.$number.'</nNF><cNF>12345678</cNF><tpAmb>2</tpAmb><tpEmis>1</tpEmis><tpNF>1</tpNF><idDest>1</idDest><finNFe>1</finNFe></ide>'
            .'<emit><CNPJ>12345678000100</CNPJ></emit><total><ICMSTot><vNF>100.00</vNF></ICMSTot></total></infNFe><Signature xmlns="http://www.w3.org/2000/09/xmldsig#"/></NFe>');
        Storage::disk('local')->put($note->response_path, "[Envio]\nCStat=486\nXMotivo=Rejeicao: Nao informado o Grupo de Autorizacao para UF\n");
        return $note;
    }

    private function completeDraft(): NfeDraft
    {
        $note = $this->draft();
        CompanySetting::current()->update([
            'legal_name' => 'Empresa NF-e Teste', 'state_registration' => '12345678',
            'city' => 'Salvador', 'address' => 'Rua Emitente', 'address_number' => '10',
            'district' => 'Centro', 'zip_code' => '40000000', 'crt' => '1',
        ]);
        $nature = OperationNature::query()->create([
            'name' => 'VENDA DE MERCADORIA', 'operation_type' => 'outbound', 'purpose' => 'normal',
            'cfop_internal' => '5102', 'is_active' => true,
        ]);
        $note->update([
            'operation_nature_id' => $nature->id, 'presence' => 'presential',
            'recipient_snapshot' => [
                'name' => 'Comprador local', 'document' => '12345678000190',
                'state' => 'BA', 'city' => 'Salvador', 'city_ibge_code' => '2927408',
                'address' => 'Rua Cliente', 'address_number' => '3', 'district' => 'Centro',
                'zip_code' => '40001000', 'ie_indicator' => 'non_contributor',
            ],
            'totals' => ['products' => 100, 'total' => 100], 'payment_type' => '01',
            'payments' => [['type' => '01', 'amount' => 100, 'condition' => 'a_vista']],
            'discount' => 0, 'surcharge' => 0, 'transport_data' => ['freight_value' => 0],
            'validated_at' => now(),
        ]);
        $note->items()->create([
            'item_number' => 1, 'product_name' => 'MERCADORIA', 'product_sku' => 'ITEM-001',
            'quantity' => '2.0000', 'dimension_quantity' => '1.0000', 'unit_price' => '50.0000',
            'cfop' => '5102', 'origin' => '0', 'unit' => 'UN', 'tax_unit' => 'UN', 'ncm' => '61091000',
            'tax_data' => ['icms_csosn' => '102', 'pis_cst' => '49', 'pis_rate' => '0', 'cofins_cst' => '49', 'cofins_rate' => '0', 'ipi_cst' => '53', 'ipi_enq' => '999'],
        ]);
        $note = $note->fresh(['items', 'operationNature']);
        $note->update(['custom_fields' => ['_reserved_fiscal_fingerprint' => app(Nfe55DocumentSnapshot::class)->materialFingerprint($note, CompanySetting::current())]]);
        $disk = Storage::disk('local');
        $disk->put($note->xml_path, str_replace('<total>', '<det nItem="1"><prod/></det><total>', $disk->get($note->xml_path)));
        return $note;
    }

    public function test_explicit_corrected_authorization_prepares_and_queues_the_same_reserved_document_once(): void
    {
        Bus::fake();
        $note = $this->completeDraft();
        $identity = $note->only(['id', 'access_key', 'document_number', 'numeric_code', 'custom_fields']);
        $xml = Storage::disk('local')->get($note->xml_path);
        AppSetting::put('accounting', 'accountant_document', '11222333000181');
        AppSetting::put('accounting', 'accountant_xml_authorized', true);
        $service = app(Nfe55RejectionRecoveryService::class);
        $prepared = $service->prepareCorrection486($note);
        self::assertSame('prepared', $prepared->status);
        self::assertNull($prepared->transmission_attempted_at);
        self::assertSame($identity, $prepared->only(array_keys($identity)));
        self::assertSame($xml, Storage::disk('local')->get($prepared->xml_path));
        self::assertCount(3, Storage::disk('local')->allFiles('fiscal/nfe55/'.$note->id.'/attempts'));
        self::assertSame('queued', app(Nfe55EmissionService::class)->retryPreparation($prepared)->status);
        Bus::assertDispatchedTimes(ProcessNfe55Job::class, 1);
        try {
            $service->prepareCorrection486($prepared);
            self::fail('A prepared correction must never prepare or dispatch another attempt.');
        } catch (ValidationException) {
            Bus::assertDispatchedTimes(ProcessNfe55Job::class, 1);
        }
    }

    public function test_missing_bahia_authorization_cannot_clear_the_original_attempt(): void
    {
        Bus::fake();
        $note = $this->completeDraft();
        $attempt = $note->transmission_attempted_at->toIso8601String();
        try {
            app(Nfe55RejectionRecoveryService::class)->prepareCorrection486($note);
            self::fail('Bahia correction requires explicit XML authorization.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('486', $exception->getMessage());
            self::assertSame('pending', $note->fresh()->status);
            self::assertSame($attempt, $note->fresh()->transmission_attempted_at->toIso8601String());
            Bus::assertNothingDispatched();
        }
    }

    public function test_archived_486_reclassifies_same_draft_without_transmitting_or_clearing_attempt(): void
    {
        Bus::fake();
        $note = $this->draft();
        $original = $note->getAttributes();
        $xml = Storage::disk('local')->get($note->xml_path);
        $raw = Storage::disk('local')->get($note->response_path);
        $service = app(Nfe55RejectionRecoveryService::class);
        $recovered = $service->recover486($note);
        self::assertSame('rejected', $recovered->status);
        foreach (['id', 'access_key', 'document_number', 'numeric_code', 'transmission_attempted_at', 'custom_fields'] as $field) self::assertSame($original[$field], $recovered->getAttributes()[$field]);
        self::assertSame($xml, Storage::disk('local')->get($note->xml_path));
        self::assertSame($raw, Storage::disk('local')->get($note->response_path));
        self::assertCount(3, Storage::disk('local')->allFiles('fiscal/nfe55/'.$note->id.'/attempts'));
        self::assertSame('rejected', $service->recover486($recovered)->status);
        Bus::assertNothingDispatched();
    }

    public function test_ambiguous_foreign_authorized_duplicate_and_consultation_evidence_stays_pending(): void
    {
        foreach ([
            "[Envio]\nCStat=100\nXMotivo=Autorizado\n",
            "[Envio]\nCStat=204\nXMotivo=Rejeicao: Duplicidade\n",
            "[Envio]\nCStat=486\nXMotivo=Sem confirmacao\n",
            "[Envio]\nCStat=486\nXMotivo=Rejeicao: autXML\nchDFe=".str_repeat('1', 44),
            "[NFE1]\nCStat=486\nXMotivo=Rejeicao: autXML\n[NFE2]\nCStat=100\n",
            "[NFE99]\nCStat=486\nXMotivo=Rejeicao: autXML\n",
            "[Consulta]\nCStat=486\nXMotivo=Rejeicao: autXML\n",
        ] as $raw) {
            $note = $this->draft();
            Storage::disk('local')->put($note->response_path, $raw);
            try {
                app(Nfe55RejectionRecoveryService::class)->recover486($note);
                self::fail('Unproven negative fiscal evidence must not enable correction.');
            } catch (ValidationException) {
                self::assertSame('pending', $note->fresh()->status);
                self::assertNotNull($note->fresh()->transmission_attempted_at);
            }
        }
    }


    public function test_rejection_487_requires_definitive_response_and_preserves_fiscal_identity(): void
    {
        Bus::fake();
        $note = $this->completeDraft();
        $disk = Storage::disk('local');
        $disk->put($note->response_path, "[Envio]\nCStat=487\nTpAmb=2\nXMotivo=Rejeicao: Escritorio de Contabilidade nao cadastrado na SEFAZ\n");
        AppSetting::put('accounting', 'accountant_xml_authorized', false);
        AppSetting::put('accounting', 'nfe_ba_sefaz_xml_authorized', true);
        $original = $note->only(['id', 'access_key', 'document_number', 'numeric_code']);
        $prepared = app(Nfe55RejectionRecoveryService::class)->prepareCorrection487($note);
        self::assertSame('prepared', $prepared->status);
        self::assertSame($original, $prepared->only(array_keys($original)));
        self::assertTrue($disk->exists('fiscal/nfe55/'.$note->id.'/attempts/rejection-487-'.hash('sha256', $disk->get($note->xml_path)."\0".$disk->get($note->response_path)).'/attempt.json'));
        self::assertSame('queued', app(Nfe55EmissionService::class)->retryPreparation($prepared)->status);
        Bus::assertDispatchedTimes(ProcessNfe55Job::class, 1);
    }

    public function test_rejection_487_does_not_accept_486_or_authorization_as_evidence(): void
    {
        $parser = app(NFCeSefazResponseParser::class);
        $key = '29261012345678000100550010000000231123456780';
        self::assertTrue($parser->definitiveNfe55AuthorizationRejection("[Envio]\\nCStat=487\\nTpAmb=2\\nXMotivo=Rejeicao: escritorio nao cadastrado\\n", $key, 'homologation', ['487']));
        self::assertFalse($parser->definitiveNfe55AuthorizationRejection("[Envio]\\nCStat=100\\nTpAmb=2\\nXMotivo=Autorizado\\n", $key, 'homologation', ['487']));
        self::assertFalse($parser->definitiveNfe55AuthorizationRejection("[Envio]\\nCStat=486\\nTpAmb=2\\nXMotivo=Rejeicao: autXML\\n", $key, 'homologation', ['487']));
    }

    public function test_shared_nfce_correction_policy_does_not_expand_to_486(): void
    {
        $parser = app(NFCeSefazResponseParser::class);
        $raw = "[Envio]\nCStat=486\nXMotivo=Rejeicao: autXML\n";
        self::assertFalse($parser->definitiveCorrectionRejection($parser->parse($raw)));
    }

    public function test_matching_not_found_consultation_supports_original_rejection_and_is_archived(): void
    {
        $note = $this->draft();
        $raw = "[Consulta]\nCStat=217\nXMotivo=Rejeicao: NF-e nao consta na base\nTpAmb=2\nchNFe=".$note->access_key."\n";
        Storage::disk('local')->put('fiscal/nfe55/'.$note->id.'/consult-response.ini', $raw);
        self::assertSame('rejected', app(Nfe55RejectionRecoveryService::class)->recover486($note)->status);
        $files = Storage::disk('local')->allFiles('fiscal/nfe55/'.$note->id.'/attempts');
        self::assertCount(4, $files);
        foreach ($files as $path) {
            if (str_ends_with($path, '/consult-response.ini')) self::assertSame($raw, Storage::disk('local')->get($path));
        }
    }

    public function test_duplicate_ini_sections_or_fields_cannot_hide_authorization(): void
    {
        $parser = app(NFCeSefazResponseParser::class);
        foreach ([
            "[Envio]\nCStat=100\n[Envio]\nCStat=486\nXMotivo=Rejeicao: autXML\n",
            "[Envio]\nCStat=100\nCStat=486\nXMotivo=Rejeicao: autXML\n",
            "[Envio]\nCStat=486\nXMotivo=Rejeicao: autXML\nXml=<protNFe><cStat>100</cStat></protNFe>\n",
        ] as $raw) self::assertFalse($parser->definitiveNfe55Rejection486($raw, '29261012345678000100550010000000231123456780', 'homologation'));
    }

    public function test_changed_material_content_or_any_later_consultation_blocks_recovery(): void
    {
        foreach (['changed', 'consulted'] as $case) {
            $note = $this->draft();
            if ($case === 'changed') $note->update(['additional_info' => 'Changed after reservation']);
            else Storage::disk('local')->put('fiscal/nfe55/'.$note->id.'/consult-response.ini', "[Consulta]\nCStat=100\n");
            try {
                app(Nfe55RejectionRecoveryService::class)->recover486($note);
                self::fail('Changed or subsequently queried evidence requires reconciliation.');
            } catch (ValidationException) {
                self::assertSame('pending', $note->fresh()->status);
            }
        }
    }
}
