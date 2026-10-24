<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\NfeDraft;
use App\Models\OperationNature;
use App\Models\User;
use App\Services\Fiscal\Nfe55IniBuilder;
use App\Services\Fiscal\ACBr\ACBrNFeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithConfiguredCompany;
use Tests\TestCase;
use RuntimeException;

final class Nfe55EmissionFoundationTest extends TestCase
{
    use RefreshDatabase,WithConfiguredCompany;

    private function admin(): User
    {
        return User::query()->create([
            'name'=>'Operador NF-e modelo 55','email'=>'nfe55@example.test',
            'password'=>'password','role'=>'admin','is_active'=>true,
        ]);
    }

    private function draft(): NfeDraft
    {
        CompanySetting::current()->update([
            'document'=>'12345678000100','legal_name'=>'Empresa NF-e Teste',
            'state_registration'=>'12345678','state'=>'BA','city'=>'Salvador',
            'city_ibge_code'=>'2927408','address'=>'Rua Emitente',
            'address_number'=>'10','district'=>'Centro','zip_code'=>'40000000','crt'=>'1',
        ]);
        $nature=OperationNature::query()->create([
            'name'=>'VENDA DE MERCADORIA','operation_type'=>'outbound','purpose'=>'normal',
            'cfop_internal'=>'5102','is_active'=>true,
        ]);
        $customer=Customer::query()->create([
            'name'=>'Comprador local','document'=>'12345678000190',
            'is_customer'=>true,'state'=>'BA','city'=>'Salvador',
            'city_ibge_code'=>'2927408','address'=>'Rua Cliente',
            'address_number'=>'3','district'=>'Centro','zip_code'=>'40001000',
            'ie_indicator'=>'non_contributor',
        ]);
        $draft=NfeDraft::query()->create([
            'operation_nature_id'=>$nature->id,'customer_id'=>$customer->id,
            'user_id'=>$this->admin()->id,'status'=>'draft',
            'operation_type'=>'outbound','destination'=>'internal',
            'presence'=>'presential','purpose'=>'normal',
            'issue_date'=>'2026-10-23','issue_time'=>'14:00',
            'series'=>1,'environment'=>'homologation',
            'recipient_snapshot'=>$customer->only([
                'name','document','state','city','city_ibge_code','address','address_number',
                'district','zip_code','ie_indicator',
            ]),
            'totals'=>['products'=>100,'total'=>100],
            'payment_type'=>'01','payments'=>[['type'=>'01','amount'=>100,'condition'=>'a_vista']],
            'discount'=>0,'surcharge'=>0,
            'transport_data'=>['freight_value'=>0],
            'validated_at'=>now(),
        ]);
        $draft->items()->create([
            'item_number'=>1,'product_name'=>'MERCADORIA','product_sku'=>'ITEM-001',
            'quantity'=>'2.0000','dimension_quantity'=>'1.0000','unit_price'=>'50.0000',
            'cfop'=>'5102','origin'=>'0','unit'=>'UN','tax_unit'=>'UN','ncm'=>'61091000',
            'tax_data'=>['icms_csosn'=>'102','pis_cst'=>'49','cofins_cst'=>'49','ipi_cst'=>'53'],
        ]);
        return $draft->refresh();
    }

    public function test_model55_builder_produces_homologation_invoice_with_real_invoice_identification(): void
    {
        $draft=$this->draft();
        $ini=app(Nfe55IniBuilder::class)->build($draft,CompanySetting::current(),24,12345678);
        foreach([
            'Modelo=55','cUF=29','cNF=12345678','serie=1','nNF=24',
            'tpAmb=2','indFinal=0','CNPJCPF=12345678000100',
            'dhEmi=23/10/2026 14:00:00',
            'NOTA FISCAL EMITIDA EM AMBIENTE DE HOMOLOGACAO - SEM VALOR FISCAL',
            'NCM=61091000','CFOP=5102','CSOSN=102','vProd=100.00','vNF=100.00',
            '[Produto001]','[PIS001]','[COFINS001]','[pag001]',
        ] as $piece)self::assertStringContainsString($piece,$ini);
        self::assertStringNotContainsString('Modelo=65',$ini);
        self::assertStringNotContainsString('ArquivoPFX',$ini);
    }

    public function test_unsupported_icms_tax_scenario_is_blocked_before_any_sefaz_transmission(): void
    {
        $draft=$this->draft();
        $draft->items()->first()->update(['tax_data'=>[
            'icms_csosn'=>'500','pis_cst'=>'49','cofins_cst'=>'49','ipi_cst'=>'53',
        ]]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CSOSN 500');
        app(Nfe55IniBuilder::class)->build($draft->fresh(),CompanySetting::current(),24,12345678);
    }

    public function test_production_is_not_automatically_enabled_by_the_editor(): void
    {
        $draft=$this->draft();
        $draft->update(['environment'=>'production']);
        $this->actingAs(User::query()->where('email','nfe55@example.test')->firstOrFail());
        $this->post(route('fiscal.nfe.emit',$draft))
            ->assertSessionHasErrors('nfe');
        self::assertSame('draft',$draft->fresh()->status);
        self::assertNull($draft->fresh()->document_number);
    }

    public function test_unvalidated_note_cannot_reserve_number(): void
    {
        $draft=$this->draft();
        $draft->update(['validated_at'=>null]);
        $this->actingAs(User::query()->where('email','nfe55@example.test')->firstOrFail());
        $this->post(route('fiscal.nfe.emit',$draft))->assertSessionHasErrors('nfe');
        self::assertNull($draft->fresh()->document_number);
    }

    public function test_authorized_or_queued_note_cannot_be_edited_or_deleted(): void
    {
        $draft=$this->draft();
        $draft->update(['status'=>'queued','document_number'=>101]);
        $this->actingAs(User::query()->where('email','nfe55@example.test')->firstOrFail());
        $this->delete(route('fiscal.nfe.destroy',$draft))->assertStatus(409);
        $this->put(route('fiscal.nfe.update',$draft),[])->assertStatus(409);
    }

    public function test_acbr_business_rule_preflight_rejects_errors_in_returned_text(): void
    {
        ACBrNFeService::assertBusinessRulesClear('');
        ACBrNFeService::assertBusinessRulesClear('OK');
        ACBrNFeService::assertBusinessRulesClear('Sem erros.');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('226-Rejeição');
        ACBrNFeService::assertBusinessRulesClear(
            'Erro(s) nas Regras de negócios da nota: 1 226-Rejeição: UF divergente'
        );
    }

    public function test_acbr_preflight_refuses_unvalidated_draft_without_reserving_number(): void
    {
        $draft=$this->draft();
        $draft->update(['validated_at'=>null]);
        $this->actingAs(User::query()->where('email','nfe55@example.test')->firstOrFail());

        $this->post(route('fiscal.nfe.preflight',$draft))->assertSessionHasErrors('nfe');

        self::assertSame('draft',$draft->fresh()->status);
        self::assertNull($draft->fresh()->document_number);
        self::assertNull($draft->fresh()->transmission_attempted_at);
    }

    public function test_acbr_preflight_will_not_test_production_or_reserve_number(): void
    {
        $draft=$this->draft();
        $draft->update(['environment'=>'production']);
        $this->actingAs(User::query()->where('email','nfe55@example.test')->firstOrFail());

        $this->post(route('fiscal.nfe.preflight',$draft))->assertSessionHasErrors('nfe');

        self::assertSame('draft',$draft->fresh()->status);
        self::assertNull($draft->fresh()->document_number);
        self::assertNull($draft->fresh()->access_key);
    }

    public function test_signed_xml_must_match_issuer_even_when_key_contains_another_cnpj(): void
    {
        $draft=$this->draft();
        $draft->update(['document_number'=>24,'numeric_code'=>12345678]);
        $wrongIssuer='99999999000100';
        $xml='<NFe xmlns="http://www.portalfiscal.inf.br/nfe">'
            .'<infNFe Id="NFe292610'.$wrongIssuer.'550010000000241123456781">'
            .'<ide><mod>55</mod><serie>1</serie><nNF>24</nNF><tpEmis>1</tpEmis>'
            .'<tpAmb>2</tpAmb><cNF>12345678</cNF></ide>'
            .'<emit><CNPJ>'.$wrongIssuer.'</CNPJ></emit></infNFe>'
            .'<Signature xmlns="http://www.w3.org/2000/09/xmldsig#"/>'
            .'</NFe>';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('XML assinado não corresponde');
        app(\\App\\Services\\Fiscal\\Nfe55EmissionService::class)
            ->verifySignedXml($xml,$draft,CompanySetting::current());
    }
}
