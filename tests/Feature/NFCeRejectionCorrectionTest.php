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
        $this->get(route('fiscal.show',$old))->assertOk()->assertSee('Corrigir rejeição');
        $this->get(route('fiscal.nfce.correct',$old))
            ->assertOk()->assertSee('Salvar nova versão fiscal');

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

    public function test_gtin_and_tax_codes_remain_subject_to_validation(): void
    {
        $old=$this->rejected('611');
        $this->expectException(ValidationException::class);
        app(NFCeRejectionCorrectionService::class)->prepare(
            $old->id,$this->itemPayload(['gtin'=>'12345678']),$this->admin()->id
        );
    }
}
