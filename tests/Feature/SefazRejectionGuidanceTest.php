<?php

namespace Tests\Feature;

use App\Models\FiscalDocumentJob;
use App\Models\NFCeInutilization;
use App\Models\Nfe55Inutilization;
use App\Models\NfeDraft;
use App\Services\Fiscal\SefazRejectionGuidance;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class SefazRejectionGuidanceTest extends TestCase
{
    private function note(array $attributes = []): NfeDraft
    {
        Storage::fake('local');
        $note = new NfeDraft($attributes + [
            'status' => 'rejected', 'environment' => 'homologation',
            'access_key' => str_repeat('1', 44),
            'response_path' => 'fiscal/nfe55/12/sefaz-response.ini',
        ]);
        $note->id = 12;
        return $note;
    }

    private function guide(): SefazRejectionGuidance
    {
        return app(SefazRejectionGuidance::class);
    }

    public function test_official_catalog_is_sourced_and_every_code_has_readable_guidance(): void
    {
        $catalog = json_decode(file_get_contents(resource_path('fiscal/rejections/2026.10.26.json')), true, 512, JSON_THROW_ON_ERROR);
        self::assertGreaterThan(900, count($catalog['codes']));
        $invalid = [];
        foreach ($catalog['codes'] as $code => $entry) {
            $guide = $this->guide()->describe((string) $code, $entry['message']);
            if (!$guide['known_code'] || !$guide['title'] || !$guide['summary']
                || !$guide['steps'] || !$guide['sources'] || empty($entry['references'])) $invalid[] = $code;
        }
        self::assertSame([], $invalid, 'Every published code needs a usable explanation and its official source.');
    }

    public function test_item_and_four_digit_rtc_code_are_read_from_the_individual_return(): void
    {
        $note = $this->note();
        Storage::disk('local')->put($note->response_path,
            "[ENVIO]\nCStat=103\n[RETORNO]\nCStat=104\n[NFE12]\nCStat=1026\nXMotivo=Rejeição: Valor IBS UF difere do calculado [nItem: 2]\nTpAmb=2\nchNFe=".$note->access_key."\n");
        $before = $note->getAttributes();
        $guide = $this->guide()->forDocument($note);
        self::assertSame('1026', $guide['code']);
        self::assertTrue($guide['known_code']);
        self::assertSame(2, $guide['item']);
        self::assertSame($before, $note->getAttributes());
    }

    public function test_duplicate_response_preserves_pending_state_and_requires_consultation(): void
    {
        $note = $this->note(['status' => 'pending', 'error_message' => 'Retorno da SEFAZ: 539 - Duplicidade de NF-e']);
        $guide = $this->guide()->forDocument($note);
        self::assertSame('539', $guide['code']);
        self::assertStringContainsString('Consulte a situação', $guide['steps'][0]);
        self::assertSame('pending', $note->status);
        self::assertNull($note->protocol);
    }

    public function test_unknown_future_codes_have_an_honest_fallback_and_the_original_message(): void
    {
        $message = 'Rejeição: condição futura [nItem: 7]';
        $guide = $this->guide()->describe('9998', $message);
        self::assertFalse($guide['known_code']);
        self::assertSame('unknown', $guide['category']);
        self::assertSame($message, $guide['technical_message']);
        self::assertSame(7, $guide['item']);
        self::assertSame([], $guide['sources']);
        self::assertStringContainsString('ainda não possui orientação específica', $guide['summary']);
    }

    public function test_schema_line_numbers_are_not_mistaken_for_cstat(): void
    {
        $note = $this->note(['status' => 'prepared', 'error_message' => 'Falha de esquema na linha 778, produto 539.']);
        $guide = $this->guide()->forDocument($note);
        self::assertNull($guide['code']);
        self::assertSame('local_error', $guide['category']);
        self::assertSame($note->error_message, $guide['summary']);
    }

    public function test_a_healthy_draft_and_an_authorized_note_do_not_show_error_panels(): void
    {
        self::assertNull($this->guide()->forDocument($this->note(['status' => 'draft'])));
        self::assertNull($this->guide()->forDocument($this->note(['status' => 'authorized'])));
    }

    public function test_authorized_note_with_a_download_error_keeps_that_error_instead_of_the_old_send_reply(): void
    {
        $note = $this->note(['status' => 'authorized', 'error_message' => 'Documento autorizado; download temporariamente indisponível.']);
        Storage::disk('local')->put($note->response_path, "[NFE12]\nCStat=100\nXMotivo=Autorizado o uso\n");
        $guide = $this->guide()->forDocument($note);
        self::assertNull($guide['code']);
        self::assertSame($note->error_message, $guide['summary']);
        self::assertStringContainsString('documento já autorizado', $guide['steps'][0]);
    }

    public function test_success_codes_do_not_authorize_a_document_or_tell_the_user_it_was_rejected(): void
    {
        foreach (['100', '120', '135'] as $code) {
            $guide = $this->guide()->describe($code, 'Retorno recebido', ['state' => 'pending']);
            self::assertNotSame('rejection', $guide['category']);
            self::assertStringContainsString('Consulte a situação', $guide['steps'][0]);
        }
    }

    public function test_a_new_consultation_is_explained_separately_from_the_original_send_rejection(): void
    {
        $note = $this->note(['status' => 'pending', 'error_message' => 'Consulta SEFAZ: 217 - NF-e não consta na base de dados']);
        Storage::disk('local')->put($note->response_path, "[NFE12]\nCStat=486\nXMotivo=Rejeição: autXML\n");
        Storage::disk('local')->put('fiscal/nfe55/12/consult-response.ini', "[CONSULTA]\nCStat=217\nXMotivo=NF-e não consta na base de dados\n");
        self::assertSame('217', $this->guide()->forDocument($note)['code']);
        self::assertStringContainsString('486', Storage::disk('local')->get($note->response_path));
    }

    public function test_cancellation_explains_the_event_not_the_previously_authorized_invoice(): void
    {
        $note = $this->note(['status' => 'authorized', 'cancellation_status' => 'rejected', 'error_message' => 'Cancelamento rejeitado: Circulação comprovada']);
        Storage::disk('local')->put($note->response_path, "[NFE12]\nCStat=100\nXMotivo=Autorizado\n");
        Storage::disk('local')->put('fiscal/nfe55/12/cancel-response.ini', "[Cancelamento]\nCStat=219\nXMotivo=Rejeição: Circulação da NF-e verificada\nTpAmb=2\n");
        self::assertSame('219', $this->guide()->forDocument($note)['code']);
        self::assertSame('authorized', $note->status);
    }

    public function test_response_paths_outside_the_document_directory_are_never_read(): void
    {
        $note = $this->note(['response_path' => 'fiscal/nfe55/13/sefaz-response.ini']);
        Storage::disk('local')->put($note->response_path, "[NFE13]\nCStat=778\nXMotivo=Rejeição de outra nota\n");
        self::assertNull($this->guide()->forDocument($note)['code']);
    }

    public function test_foreign_keys_environments_and_ambiguous_sections_are_not_used_for_guidance(): void
    {
        $note = $this->note();
        foreach ([
            "[NFE12]\nCStat=778\nchNFe=".str_repeat('2', 44),
            "[NFE12]\nCStat=778\nTpAmb=1",
            "[NFE12]\nCStat=778\ncstat=539",
            "[NFE12]\nCStat=778\n[NFE12]\nCStat=539",
            "[NFE12]\nCStat=778\n[NFE13]\nCStat=539",
            "<!DOCTYPE test>\n[NFE12]\nCStat=778",
        ] as $raw) {
            Storage::disk('local')->put($note->response_path, $raw);
            self::assertNull($this->guide()->forDocument($note)['code']);
        }
    }

    public function test_nfe_and_nfce_inutilization_use_their_real_artifact_directories(): void
    {
        Storage::fake('local');
        foreach ([[Nfe55Inutilization::class, 'nfe55/inutilizations'], [NFCeInutilization::class, 'nfce/inutilization']] as [$model, $folder]) {
            $record = new $model(['status' => 'rejected', 'environment' => 'homologation']);
            $record->id = 4;
            $record->response_path = 'fiscal/'.$folder.'/4/sefaz-response.ini';
            Storage::disk('local')->put($record->response_path, "[Inutilizacao]\nCStat=256\nXMotivo=Faixa já inutilizada\nTpAmb=2\n");
            self::assertSame('256', $this->guide()->forInutilization($record)['code']);
            self::assertSame('rejected', $record->status);
        }
    }

    public function test_other_dfe_namespaces_are_not_interpreted_as_nfe_rejections(): void
    {
        $note = new FiscalDocumentJob(['document_type' => 'nfse', 'status' => 'rejected', 'error_message' => 'Retorno SEFAZ: 778 - Serviço inválido']);
        self::assertNull($this->guide()->forDocument($note));
    }

    public function test_current_rejection_and_historical_denial_keep_their_official_meanings(): void
    {
        $current = $this->guide()->describe('302', 'Rejeição: Irregularidade fiscal do destinatário');
        $historical = $this->guide()->describe('302', 'Uso Denegado: Irregularidade fiscal do destinatário');
        self::assertSame('registration', $current['category']);
        self::assertSame('denied', $historical['category']);
        self::assertStringStartsWith('Rejeição:', $current['official_message']);
        self::assertStringStartsWith('Uso Denegado:', $historical['official_message']);
    }
}
