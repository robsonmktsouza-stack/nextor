<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\CompanySetting;
use App\Models\FiscalDocumentJob;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\User;
use App\Services\Fiscal\NFCeRejectionCorrectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class NFCeRejectionCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::query()->create([
            'name'=>'Responsavel fiscal', 'email'=>'correction@example.com',
            'password'=>'senhaSegura123', 'role'=>'admin', 'is_active'=>true,
        ]);
    }

    private function rejected(string $code = '778', string $status = 'rejected'): FiscalDocumentJob
    {
        Storage::fake('local');
        config(['instance.cnpj'=>'']);
        CompanySetting::query()->create([
            'document'=>'39323356000100','legal_name'=>'Emissor homologacao',
            'state'=>'BA','city'=>'Salvador','city_ibge_code'=>'2927408',
            'address'=>'Rua Teste','district'=>'Centro','zip_code'=>'40000000',
            'state_registration'=>'123456789','crt'=>'1',
        ]);
        AppSetting::put('fiscal','enabled',true);
        AppSetting::put('nfce','enabled',true);
        AppSetting::put('nfce','environment','homologation');
        AppSetting::put('nfce','series',1);
        AppSetting::put('nfce','next_number',8);

        PaymentMethod::query()->updateOrCreate(['code'=>'cash'],[
            'name'=>'Dinheiro','kind'=>'cash','is_active'=>true,'pdv_enabled'=>true,'sort_order'=>1,
        ]);
        PaymentMethod::query()->updateOrCreate(['code'=>'pix'],[
            'name'=>'PIX','kind'=>'pix','is_active'=>true,'pdv_enabled'=>true,'sort_order'=>2,
        ]);
        $sale=Sale::query()->create([
            'operation_type'=>'sale','source'=>'nfce','status'=>'completed',
            'operation_date'=>now(),'total'=>'29.90','subtotal'=>'29.90',
        ]);
        $key=str_repeat('7',44);
        $job=FiscalDocumentJob::query()->create([
            'document_type'=>'nfce','sale_id'=>$sale->id,'status'=>$status,
            'environment'=>'homologation','emission_mode'=>'normal',
            'series'=>1,'document_number'=>7,'access_key'=>$key,
            'error_message'=>'Retorno SEFAZ: '.$code.' - erro',
            'source_snapshot'=>[
                'sale_id'=>$sale->id,'total'=>'29.90',
                'consumer_document'=>null,'consumer_name'=>null,
                'items'=>[[
                    'item_type'=>'product','product_id'=>12,'name'=>'Camiseta teste',
                    'sku'=>'TESTE-001','ncm'=>'61091000','origin'=>'0','gtin'=>'',
                    'unit'=>'UN','quantity'=>'1.000','unit_price'=>'29.90',
                    'line_total'=>'29.90','discount'=>'0.00',
                    'tax_defaults'=>[
                        'cfop_outbound_internal'=>'5102','icms_csosn'=>'102',
                        'pis_cst'=>'49','cofins_cst'=>'49',
                        'fiscal_config_source'=>'product',
                    ],
                ]],
                'payments'=>[[
                    'payment_method'=>'cash','payment_kind'=>'cash','amount'=>'29.90',
                ]],
            ],'prepared_at'=>now(),
        ]);
        $base='fiscal/nfce/'.$job->id;
        $xml='<?xml version="1.0" encoding="UTF-8"?>'
            .'<NFe xmlns="http://www.portalfiscal.inf.br/nfe">'
            .'<infNFe Id="NFe'.$key.'"><ide>'
            .'<mod>65</mod><serie>1</serie><nNF>7</nNF><tpAmb>2</tpAmb><tpEmis>1</tpEmis>'
            .'</ide></infNFe>'
            .'<Signature xmlns="http://www.w3.org/2000/09/xmldsig#"></Signature>'
            .'</NFe>';
        Storage::disk('local')->put($base.'/signed.xml',$xml);
        Storage::disk('local')->put($base.'/sefaz-response.ini',
            "[NFE7]\nCStat={$code}\nchNFe={$key}\nXMotivo=Rejeicao no item\n");
        $job->update([
            'xml_path'=>$base.'/signed.xml',
            'response_path'=>$base.'/sefaz-response.ini',
        ]);
        return $job->fresh();
    }

    private function itemPayload(array $overrides = []): array
    {
        return ['items'=>[array_replace([
            'ncm'=>'61091001','cfop'=>'5102','origin'=>'0','gtin'=>'',
            'csosn'=>'102','pis_cst'=>'49','cofins_cst'=>'49',
        ],$overrides)]];
    }

    public function test_tax_correction_generates_new_prepared_fiscal_version_without_rewriting_original(): void
    {
        $old=$this->rejected('778');
        $this->actingAs($this->admin());
        $path=$old->xml_path;
        $oldXml=Storage::disk('local')->get($path);
        $this->get(route('fiscal.show',$old))
            ->assertOk()
            ->assertSee('id="nfceEditorForm" data-readonly="0" data-correction="1"',false)
            ->assertSee('data-nfce-tab="products"',false)
            ->assertSee('Salvar e transmitir')
            ->assertDontSee('fiscal-detail-grid',false);
        $this->get(route('fiscal.nfce.correct',$old))
            ->assertOk()->assertSee('Salvar e transmitir');

        $this->post(route('fiscal.nfce.correct.store',$old),$this->itemPayload())
            ->assertRedirect();

        $copy=FiscalDocumentJob::query()->where('corrected_from_id',$old->id)->sole();
        self::assertSame('prepared',$copy->status);
        self::assertSame(7,(int)$copy->document_number);
        self::assertSame('61091001',$copy->source_snapshot['items'][0]['ncm']);
        self::assertSame('61091000',$old->fresh()->source_snapshot['items'][0]['ncm']);
        self::assertNull($copy->xml_path);
        self::assertNull($copy->access_key);
        self::assertSame($old->sale_id,$copy->sale_id);
        self::assertSame(1,Sale::query()->count());
        self::assertSame(2,FiscalDocumentJob::query()->count());
        self::assertSame(8,(int)AppSetting::value('nfce','next_number'));
        self::assertSame($oldXml,Storage::disk('local')->get($path));

        $revision=DB::table('nfce_correction_revisions')->sole();
        self::assertSame('778',$revision->rejection_code);
        self::assertSame(hash('sha256',$oldXml),$revision->original_xml_sha256);
        self::assertSame($copy->id,$revision->corrected_document_id);
        $this->get(route('fiscal.show',$copy))->assertOk()->assertSee('Versão fiscal corrigida');

        // Repeat click is idempotent: no duplicate fiscal version or sale.
        $this->post(route('fiscal.nfce.correct.store',$old),$this->itemPayload())
            ->assertRedirect(route('fiscal.show',$copy));
        self::assertSame(2,FiscalDocumentJob::query()->count());
    }

    public function test_consumer_rejection_accepts_valid_document_without_modifying_sale(): void
    {
        $old=$this->rejected('237');
        $copy=app(NFCeRejectionCorrectionService::class)->prepare($old->id,[
            'consumer_document'=>'39323356000100','consumer_name'=>'Cliente fiscal',
        ],$this->admin()->id);
        self::assertSame('39323356000100',$copy->source_snapshot['consumer_document']);
        self::assertSame('Cliente fiscal',$copy->source_snapshot['consumer_name']);
        self::assertNull($old->fresh()->source_snapshot['consumer_document']);
        self::assertSame(1,Sale::query()->count());
    }

    public function test_payment_correction_keeps_sale_total_and_original_financial_data(): void
    {
        $old=$this->rejected('769');
        $copy=app(NFCeRejectionCorrectionService::class)->prepare($old->id,[
            'payments'=>[['payment_method'=>'pix','amount'=>'29.90']],
        ],$this->admin()->id);
        self::assertSame('pix',$copy->source_snapshot['payments'][0]['payment_kind']);
        self::assertSame('cash',$old->fresh()->source_snapshot['payments'][0]['payment_kind']);
        self::assertSame('29.90',(string)$old->sale->total);
        self::assertSame(1,Sale::query()->count());
    }

    public function test_archived_retorno_778_is_reconciled_without_reissuing_or_touching_sale(): void
    {
        $old=$this->rejected('778','pending');
        $base='fiscal/nfce/'.$old->id;
        Storage::disk('local')->put($base.'/sefaz-response.ini',
            "[ENVIO]\\nCStat=103\\nXMotivo=Lote recebido\\n"
            ."[RETORNO]\\nCStat=778\\nXMotivo=Rejeicao: Informado NCM inexistente [nItem:1]\\n"
        );
        $signed=Storage::disk('local')->get($old->xml_path);

        $this->artisan('nfce:reconcile-rejection',[
            'number'=>'7','--series'=>'1',
        ])->assertExitCode(0);
        self::assertSame('pending',$old->fresh()->status);

        $this->artisan('nfce:reconcile-rejection',[
            'number'=>'7','--series'=>'1','--apply'=>true,
        ])->assertExitCode(0);
        self::assertSame('rejected',$old->fresh()->status);
        self::assertSame($signed,Storage::disk('local')->get($old->xml_path));
        self::assertSame(1,Sale::query()->count());
        self::assertSame(1,FiscalDocumentJob::query()->count());

        $this->actingAs($this->admin())->get(route('fiscal.show',$old))
            ->assertOk()->assertSee('Salvar e transmitir')
            ->assertSee('data-nfce-tab="products"',false);
    }

    public function test_reconciliation_rejects_unconfirmed_613_and_authorized_sibling(): void
    {
        $old=$this->rejected('778','pending');
        Storage::disk('local')->put('fiscal/nfce/'.$old->id.'/sefaz-response.ini',
            "[RETORNO]\\nCStat=613\\nXMotivo=Rejeicao: Chave divergente\\n"
        );

        $this->artisan('nfce:reconcile-rejection',[
            'number'=>'7','--series'=>'1','--apply'=>true,
        ])->assertExitCode(0);
        self::assertSame('pending',$old->fresh()->status);

        Storage::disk('local')->put('fiscal/nfce/'.$old->id.'/sefaz-response.ini',
            "[RETORNO]\\nCStat=778\\nXMotivo=Rejeicao: Informado NCM inexistente [nItem:1]\\n"
        );
        FiscalDocumentJob::query()->create([
            'document_type'=>'nfce','sale_id'=>$old->sale_id,
            'status'=>'authorized','environment'=>'homologation',
            'series'=>1,'document_number'=>8,
            'protocol'=>'329260000174730','authorized_at'=>now(),
        ]);

        $this->artisan('nfce:reconcile-rejection',[
            'number'=>'7','--series'=>'1','--apply'=>true,
        ])->assertExitCode(0);
        self::assertSame('pending',$old->fresh()->status);
    }

    public function test_pending_authorized_and_duplicate_539_documents_cannot_be_corrected(): void
    {
        $old=$this->rejected('539');
        self::assertNull(app(NFCeRejectionCorrectionService::class)->rejection($old));

        $old->update(['status'=>'pending']);
        self::assertNull(app(NFCeRejectionCorrectionService::class)->rejection($old));

        $old->update(['status'=>'authorized','protocol'=>'329260000174730','authorized_at'=>now()]);
        self::assertNull(app(NFCeRejectionCorrectionService::class)->rejection($old));
    }

    public function test_invalid_amount_or_unsupported_tax_is_rejected_and_creates_no_revision(): void
    {
        $old=$this->rejected('769');
        $this->expectException(ValidationException::class);
        try {
            app(NFCeRejectionCorrectionService::class)->prepare($old->id,[
                'payments'=>[['payment_method'=>'pix','amount'=>'22.90']],
            ],$this->admin()->id);
        } finally {
            self::assertSame(1,FiscalDocumentJob::query()->count());
            self::assertSame(0,DB::table('nfce_correction_revisions')->count());
        }
    }

    public function test_saving_unmodified_tax_codes_does_not_generate_fake_new_revision(): void
    {
        $old=$this->rejected('778');
        $this->expectException(ValidationException::class);
        try {
            app(NFCeRejectionCorrectionService::class)->prepare(
                $old->id,$this->itemPayload(['ncm'=>'61091000']),$this->admin()->id
            );
        } finally {
            self::assertSame(1,FiscalDocumentJob::query()->count());
            self::assertSame(0,DB::table('nfce_correction_revisions')->count());
        }
    }

    public function test_preflight_blocks_second_nfce_if_sibling_was_authorized(): void
    {
        $old=$this->rejected('778');
        $copy=app(NFCeRejectionCorrectionService::class)->prepare(
            $old->id,$this->itemPayload(),$this->admin()->id
        );
        FiscalDocumentJob::query()->create([
            'document_type'=>'nfce','sale_id'=>$old->sale_id,
            'status'=>'authorized','environment'=>'homologation',
            'series'=>1,'document_number'=>99,
            'protocol'=>'329260000174730','authorized_at'=>now(),
        ]);
        $errors=app(\App\Services\Fiscal\NFCePreflightService::class)->validate($copy);
        self::assertContains('A venda já possui outra NFC-e autorizada ou em processamento.',$errors);
    }

    public function test_correction_snapshot_cannot_be_replaced_by_product_refresh(): void
    {
        $old = $this->rejected('778');
        $copy = app(NFCeRejectionCorrectionService::class)->prepare(
            $old->id, $this->itemPayload(), $this->admin()->id
        );
        $snapshot = $copy->source_snapshot;

        try {
            app(\App\Services\Fiscal\NFCeFiscalDataRefreshService::class)->refresh($copy);
            self::fail('Uma revisão fiscal auditada não pode ser atualizada a partir do produto.');
        } catch (ValidationException) {
            self::assertSame($snapshot, $copy->fresh()->source_snapshot);
            self::assertSame(1, DB::table('nfce_correction_revisions')->count());
        }
        $this->actingAs(User::query()->first());
        $this->get(route('fiscal.show', $copy))->assertOk()->assertDontSee('Atualizar dados fiscais');
    }

    public function test_correction_snapshot_cannot_have_rules_reapplied(): void
    {
        $old = $this->rejected('778');
        $copy = app(NFCeRejectionCorrectionService::class)->prepare(
            $old->id, $this->itemPayload(), $this->admin()->id
        );

        $this->expectException(\RuntimeException::class);
        app(\App\Services\Fiscal\NFCeTaxRuleApplicationService::class)->apply($copy->id);
    }

    public function test_pending_consultation_613_with_authorized_sibling_is_locked_and_shown_as_superseded(): void
    {
        $old=$this->rejected('778','pending');
        $old->update(['error_message'=>'Consulta SEFAZ: 613 - Chave de acesso diverge da base da SEFAZ.']);
        $authorized=FiscalDocumentJob::query()->create([
            'document_type'=>'nfce','sale_id'=>$old->sale_id,
            'status'=>'authorized','environment'=>'homologation',
            'series'=>1,'document_number'=>8,
            'access_key'=>str_repeat('8',44),
            'protocol'=>'329260000174730','authorized_at'=>now(),
            'prepared_at'=>now(),
        ]);

        self::assertNull(app(NFCeRejectionCorrectionService::class)->rejection($old->fresh()));
        $this->actingAs($this->admin());

        // Somente a nota autorizada fica na lista; a antiga continua
        // acessivel dentro do historico do proprio editor.
        $this->get(route('fiscal.index',['tab'=>'nfce']))
            ->assertOk()->assertSee('Autorizada')
            ->assertDontSee('Substituída')
            ->assertSee(route('fiscal.show',$authorized),false)
            ->assertDontSee(route('fiscal.show',$old),false);

        $this->get(route('fiscal.show',$old))
            ->assertOk()
            ->assertSee('data-readonly="1"',false)
            ->assertSee('Tentativa anterior')
            ->assertSee('Substituída')
            ->assertSee('613')
            ->assertSee(route('fiscal.show',$authorized),false)
            ->assertDontSee('Salvar e transmitir')
            ->assertDontSee('Consultar SEFAZ')
            ->assertDontSee('fiscal-detail-grid',false);

        // Um GET direto à rota de edição também não pode contornar o bloqueio.
        $this->get(route('fiscal.nfce.correct',$old))
            ->assertRedirect(route('fiscal.show',$old));
    }

    public function test_individual_rejection_is_not_editable_after_sibling_authorization(): void
    {
        $old=$this->rejected('778');
        FiscalDocumentJob::query()->create([
            'document_type'=>'nfce','sale_id'=>$old->sale_id,
            'status'=>'authorized','environment'=>'homologation',
            'series'=>1,'document_number'=>8,
            'protocol'=>'329260000174730','authorized_at'=>now(),
        ]);
        self::assertNull(app(NFCeRejectionCorrectionService::class)->rejection($old));

        $this->actingAs($this->admin())->get(route('fiscal.show',$old))
            ->assertOk()->assertSee('Substituída')->assertSee('data-readonly="1"',false)
            ->assertDontSee('Salvar e transmitir');
    }

    public function test_list_prefers_latest_attempt_without_authorization_and_preserves_audit_history(): void
    {
        $old=$this->rejected('778','rejected');
        $second=FiscalDocumentJob::query()->create([
            'document_type'=>'nfce','sale_id'=>$old->sale_id,
            'status'=>'prepared','environment'=>'homologation',
            'series'=>1,'document_number'=>8,'prepared_at'=>now(),
            'source_snapshot'=>$old->source_snapshot,
        ]);

        $this->actingAs($this->admin());
        $this->get(route('fiscal.index',['tab'=>'nfce']))
            ->assertOk()
            ->assertSee(route('fiscal.show',$second),false)
            ->assertDontSee(route('fiscal.show',$old),false);

        $this->get(route('fiscal.show',$second))
            ->assertOk()
            ->assertSee('Histórico fiscal (2 tentativas)')
            ->assertSee(route('fiscal.show',$old),false)
            ->assertSee('data-readonly="1"',false);
        self::assertSame(2,FiscalDocumentJob::query()->count());
        self::assertSame(1,Sale::query()->count());
    }

    public function test_failed_consultation_without_authorized_sibling_remains_unconfirmed(): void
    {
        $old=$this->rejected('778','pending');
        $old->update(['error_message'=>'Consulta SEFAZ: 613 - Chave divergente.']);
        $this->actingAs($this->admin());
        $this->get(route('fiscal.index',['tab'=>'nfce']))
            ->assertOk()->assertSee('Consulta inconclusiva')
            ->assertDontSee('Substituída');
        $this->get(route('fiscal.show',$old))
            ->assertOk()->assertSee('Pendente de confirmação')
            ->assertSee('Consultar SEFAZ')
            ->assertDontSee('Salvar e transmitir');
    }

    public function test_payment_revision_requires_exact_cent_total(): void
    {
        $old = $this->rejected('769');
        $this->expectException(ValidationException::class);
        app(NFCeRejectionCorrectionService::class)->prepare(
            $old->id, ['payments'=>[['payment_method'=>'pix','amount'=>'29.89']]],
            $this->admin()->id
        );
    }

    public function test_ncm_with_letters_is_not_silently_sanitized(): void
    {
        $old = $this->rejected('778');
        $this->expectException(ValidationException::class);
        app(NFCeRejectionCorrectionService::class)->prepare(
            $old->id, $this->itemPayload(['ncm'=>'invalido61091001']),
            $this->admin()->id
        );
    }

    public function test_gtin_and_tax_codes_remain_subject_to_validation(): void
    {
        $old=$this->rejected('611');
        $this->expectException(ValidationException::class);
        app(NFCeRejectionCorrectionService::class)->prepare(
            $old->id,$this->itemPayload(['gtin'=>'12345678']),$this->admin()->id
        );
    }
}
