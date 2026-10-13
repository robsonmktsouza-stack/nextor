<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\CompanySetting;
use App\Models\FiscalDocumentJob;
use App\Models\Sale;
use App\Models\User;
use App\Services\Fiscal\NFCeDuplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class NFCeDuplicationTest extends TestCase
{
    use RefreshDatabase;

    private function source(string $code = '539', bool $differentKey = true): FiscalDocumentJob
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
        $otherKey = $differentKey ? str_repeat('6', 44) : $key;
        $source = FiscalDocumentJob::query()->create([
            'document_type' => 'nfce', 'sale_id' => $sale->id,
            'status' => 'pending', 'environment' => 'homologation',
            'emission_mode' => 'normal', 'series' => 1, 'document_number' => 6,
            'access_key' => $key, 'source_snapshot' => [
                'sale_id' => $sale->id, 'total' => '29.90',
                'items' => [['product_id' => 5, 'quantity' => '1', 'unit_price' => '29.90']],
                'payments' => [['payment_method' => 'cash', 'amount' => '29.90']],
            ], 'prepared_at' => now(),
            'error_message' => 'Consulta SEFAZ: 613',
        ]);

        $base = 'fiscal/nfce/'.$source->id;
        $signed = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<NFe xmlns="http://www.portalfiscal.inf.br/nfe">'
            .'<infNFe Id="NFe'.$key.'"><ide>'
            .'<mod>65</mod><serie>1</serie><nNF>6</nNF><tpAmb>2</tpAmb><tpEmis>1</tpEmis>'
            .'</ide></infNFe>'
            .'<Signature xmlns="http://www.w3.org/2000/09/xmldsig#"></Signature>'
            .'</NFe>';
        Storage::disk('local')->put($base.'/signed.xml', $signed);
        Storage::disk('local')->put($base.'/sefaz-response.ini',
            "[NFE6]\nCStat={$code}\nXMotivo=Rejeicao: Duplicidade de NF-e, com diferenca na Chave de Acesso [chNFe:{$otherKey}]\n"
        );
        Storage::disk('local')->put($base.'/consult-response.ini',
            "[CONSULTA]\nCStat=613\nXMotivo=Chave difere da existente\n"
        );

        $source->update([
            'xml_path' => $base.'/signed.xml',
            'response_path' => $base.'/consult-response.ini',
        ]);
        return $source->fresh();
    }

    private function admin(): User
    {
        return User::query()->create([
            'name' => 'Fiscal', 'email' => 'duplicate-test@example.com',
            'password' => 'senhaSegura123', 'role' => 'admin', 'is_active' => true,
        ]);
    }

    public function test_539_utility_duplicate_creates_separate_fiscal_note_without_new_sale(): void
    {
        $old = $this->source();
        $this->actingAs($this->admin());
        self::assertTrue(app(NFCeDuplicationService::class)->eligible($old));
        $this->get(route('fiscal.show', $old))->assertOk()
            ->assertSee('Duplicar NFC-e')
            ->assertDontSee('Corrigir numeração');

        $oldXml = Storage::disk('local')->get($old->xml_path);
        $this->post(route('fiscal.nfce.duplicate', $old))->assertRedirect();

        $copy = FiscalDocumentJob::query()->where('duplicated_from_id', $old->id)->sole();
        self::assertSame(7, (int) $copy->document_number);
        self::assertSame('prepared', $copy->status);
        self::assertSame('nfce', $copy->document_type);
        self::assertSame('homologation', $copy->environment);
        self::assertSame('normal', $copy->emission_mode);
        self::assertSame($old->sale_id, $copy->sale_id);
        self::assertSame($old->source_snapshot, $copy->source_snapshot);
        self::assertNull($copy->access_key);
        self::assertNull($copy->xml_path);
        self::assertSame(8, (int) AppSetting::value('nfce', 'next_number'));

        self::assertSame(1, Sale::query()->count());
        self::assertSame(2, FiscalDocumentJob::query()->count());
        self::assertSame('pending', $old->fresh()->status);
        self::assertSame(6, (int) $old->fresh()->document_number);
        self::assertSame($oldXml, Storage::disk('local')->get($old->xml_path));

        $this->get(route('fiscal.show', $copy))
            ->assertOk()->assertSee('Documento duplicado');
        $this->get(route('fiscal.show', $old))
            ->assertOk()->assertSee('Documento gerado por duplicação');

        // Repeated requests may only return the already created copy.
        $this->post(route('fiscal.nfce.duplicate', $old))
            ->assertRedirect(route('fiscal.show', $copy));
        self::assertSame(2, FiscalDocumentJob::query()->count());
        self::assertSame(8, (int) AppSetting::value('nfce', 'next_number'));
    }

    public function test_inconclusive_consultation_or_non_539_rejection_does_not_allow_duplicate(): void
    {
        $source = $this->source('613');
        self::assertFalse(app(NFCeDuplicationService::class)->eligible($source));

        try {
            app(NFCeDuplicationService::class)->duplicate($source->id);
            self::fail('Rejeição diferente de 539 não pode duplicar por este fluxo.');
        } catch (ValidationException) {
            self::assertSame(1, FiscalDocumentJob::query()->count());
        }

        Storage::disk('local')->put('fiscal/nfce/'.$source->id.'/sefaz-response.ini',
            "[RETORNO]\nCStat=539\nXMotivo=Duplicidade sem chave referenciada\n");
        self::assertFalse(app(NFCeDuplicationService::class)->eligible($source));
        self::assertSame(7, (int) AppSetting::value('nfce', 'next_number'));
    }

    public function test_same_access_key_or_local_number_collision_is_blocked(): void
    {
        $source = $this->source('539', false);
        self::assertFalse(app(NFCeDuplicationService::class)->eligible($source));

        Storage::disk('local')->put('fiscal/nfce/'.$source->id.'/sefaz-response.ini',
            "[RETORNO]\nCStat=539\nXMotivo=Rejeicao: Duplicidade com diferenca na Chave de Acesso [chNFe:".str_repeat('6', 44)."]\n");
        FiscalDocumentJob::query()->create([
            'document_type' => 'nfce', 'status' => 'rejected',
            'environment' => 'homologation', 'series' => 1,
            'document_number' => 7,
        ]);

        $this->expectException(ValidationException::class);
        app(NFCeDuplicationService::class)->duplicate($source->id);
    }
}
