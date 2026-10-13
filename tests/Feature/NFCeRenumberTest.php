<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\CompanySetting;
use App\Models\FiscalDocumentJob;
use App\Models\NFCeRenumberHistory;
use App\Models\Sale;
use App\Models\User;
use App\Services\Fiscal\NFCeRenumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class NFCeRenumberTest extends TestCase
{
    use RefreshDatabase;

    private function pendingDuplicate(string $responseCode = '539', string $environment = 'homologation'): FiscalDocumentJob
    {
        Storage::fake('local');

        config(['instance.cnpj' => '']);
        CompanySetting::query()->create([
            'document' => '39323356000100',
            'legal_name' => 'Mercadinho homologacao',
            'state' => 'BA', 'city' => 'Salvador', 'city_ibge_code' => '2927408',
            'address' => 'Rua Teste', 'district' => 'Centro', 'zip_code' => '40000000',
            'state_registration' => '123456789', 'crt' => '1',
        ]);
        AppSetting::put('fiscal', 'enabled', true);
        AppSetting::put('nfce', 'enabled', true);
        AppSetting::put('nfce', 'environment', 'homologation');
        AppSetting::put('nfce', 'series', 1);
        AppSetting::put('nfce', 'next_number', 7);

        $sale = Sale::query()->create([
            'operation_type' => 'sale', 'source' => 'nfce',
            'status' => 'completed', 'operation_date' => now(),
            'total' => '29.90', 'subtotal' => '29.90',
        ]);

        $key = str_repeat('7', 44);
        $document = FiscalDocumentJob::query()->create([
            'document_type' => 'nfce', 'sale_id' => $sale->id,
            'status' => 'pending', 'environment' => $environment,
            'emission_mode' => 'normal', 'series' => 1, 'document_number' => 6,
            'access_key' => $key, 'source_snapshot' => [
                'sale_id' => $sale->id, 'total' => '29.90',
            ], 'prepared_at' => now(),
            'error_message' => 'Consulta SEFAZ: 613 - retorno inconclusivo',
        ]);

        $base = 'fiscal/nfce/'.$document->id;
        $signed = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<NFe xmlns="http://www.portalfiscal.inf.br/nfe">'
            .'<infNFe Id="NFe'.$key.'"><ide>'
            .'<mod>65</mod><serie>1</serie><nNF>6</nNF>'
            .'<tpAmb>'.($environment === 'homologation' ? '2' : '1').'</tpAmb>'
            .'<tpEmis>1</tpEmis>'
            .'</ide></infNFe>'
            .'<Signature xmlns="http://www.w3.org/2000/09/xmldsig#"></Signature>'
            .'</NFe>';

        Storage::disk('local')->put($base.'/signed.xml', $signed);
        Storage::disk('local')->put($base.'/sefaz-response.ini',
            "[NFE6]\nCStat={$responseCode}\nchNFe={$key}\nXMotivo=Rejeicao por duplicidade\n");
        Storage::disk('local')->put($base.'/consult-response.ini',
            "[CONSULTA]\nCStat=613\nXMotivo=Chave de acesso difere da existente\n");

        $document->update([
            'xml_path' => $base.'/signed.xml',
            'response_path' => $base.'/consult-response.ini',
        ]);

        return $document->refresh();
    }

    public function test_renumber_after_definitive_539_preserves_original_documents_and_sale(): void
    {
        $original = $this->pendingDuplicate();
        $user = User::query()->create([
            'name' => 'Fiscal', 'email' => 'renumber-test@example.com',
            'password' => 'senhaSegura123', 'role' => 'admin', 'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('fiscal.show', $original))
            ->assertOk()
            ->assertSee('Corrigir numeração');

        $this->actingAs($user)
            ->post(route('fiscal.nfce.renumber', $original))
            ->assertRedirect(route('fiscal.show', $original));

        $current = $original->fresh();
        self::assertSame('prepared', $current->status);
        self::assertSame(7, (int) $current->document_number);
        self::assertNull($current->access_key);
        self::assertNull($current->xml_path);
        self::assertNull($current->protocol);
        self::assertSame(8, (int) AppSetting::value('nfce', 'next_number'));

        self::assertSame(1, Sale::query()->count());
        self::assertSame(1, FiscalDocumentJob::query()->count());
        self::assertSame($original->sale_id, $current->sale_id);

        $audit = NFCeRenumberHistory::query()->sole();
        self::assertSame(6, (int) $audit->old_number);
        self::assertSame(7, (int) $audit->new_number);
        self::assertSame(str_repeat('7', 44), $audit->old_access_key);
        self::assertTrue(Storage::disk('local')->exists($audit->old_signed_xml_path));
        self::assertTrue(Storage::disk('local')->exists($audit->old_sefaz_response_path));
        self::assertTrue(Storage::disk('local')->exists($audit->old_consult_response_path));

        $this->actingAs($user)
            ->get(route('fiscal.show', $current))
            ->assertOk()
            ->assertSee('Histórico de correção de numeração')
            ->assertSee('Emitir NFC-e');

        try {
            app(NFCeRenumberService::class)->prepareWithNextNumber($current->id);
            self::fail('Uma segunda correção sem rejeição 539 deve ser bloqueada.');
        } catch (ValidationException) {
            self::assertSame(1, NFCeRenumberHistory::query()->count());
            self::assertSame(8, (int) AppSetting::value('nfce', 'next_number'));
        }
    }

    public function test_does_not_renumber_ambiguous_or_production_documents(): void
    {
        $pending = $this->pendingDuplicate('613');

        self::assertFalse(app(NFCeRenumberService::class)->eligible($pending));
        try {
            app(NFCeRenumberService::class)->prepareWithNextNumber($pending->id);
            self::fail('Uma consulta inconclusiva não autoriza nova numeração.');
        } catch (ValidationException) {
            self::assertSame(6, (int) $pending->fresh()->document_number);
            self::assertSame(0, NFCeRenumberHistory::query()->count());
        }

        $pending->update(['environment' => 'production']);
        self::assertFalse(app(NFCeRenumberService::class)->eligible($pending));
    }
}
