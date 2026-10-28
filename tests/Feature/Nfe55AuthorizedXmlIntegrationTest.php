<?php

namespace Tests\Feature;

use App\Models\CompanySetting;
use App\Models\NfeDraft;
use App\Models\NfeDraftItem;
use App\Models\OperationNature;
use App\Services\Fiscal\Nfe55DraftTotalsService;
use App\Services\Fiscal\Nfe55IniBuilder;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;
use Tests\TestCase;

final class Nfe55AuthorizedXmlIntegrationTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    private const REFERENCE = '29261012345678000100550010000000411123456789';

    private function invoice(array $additionalTax, float $total = 100.0): array
    {
        $company = new CompanySetting([
            'document'=>'12345678000100','legal_name'=>'EMITENTE TESTE',
            'state_registration'=>'12345678','state'=>'BA','city'=>'Salvador',
            'city_ibge_code'=>'2927408','address'=>'Rua Emitente','address_number'=>'10',
            'district'=>'Centro','zip_code'=>'40000000','crt'=>'1',
        ]);
        $draft = new NfeDraft([
            'status'=>'draft','operation_type'=>'outbound','destination'=>'internal',
            'presence'=>'presential','purpose'=>'normal','issue_date'=>'2026-10-25','issue_time'=>'14:00',
            'series'=>1,'environment'=>'homologation','payment_type'=>'01',
            'recipient_snapshot'=>[
                'name'=>'DESTINATARIO TESTE','document'=>'12345678000190','state'=>'BA',
                'city'=>'Salvador','city_ibge_code'=>'2927408','address'=>'Rua Destinatario',
                'address_number'=>'20','district'=>'Centro','zip_code'=>'40000000',
                'ie_indicator'=>'non_contributor',
            ],
            'totals'=>['products'=>100,'total'=>$total],
            'payments'=>[['type'=>'01','amount'=>$total,'condition'=>'a_vista']],
            'transport_data'=>['freight_value'=>0],
        ]);
        $draft->setRelation('operationNature', new OperationNature([
            'name'=>'VENDA','operation_type'=>'outbound','purpose'=>'normal','is_active'=>true,
        ]));
        $draft->setRelation('items', new Collection([new NfeDraftItem([
            'item_number'=>1,'product_name'=>'MERCADORIA','product_sku'=>'ITEM-001',
            'quantity'=>'2','dimension_quantity'=>'1','unit_price'=>'50',
            'cfop'=>'5102','origin'=>'0','unit'=>'UN','tax_unit'=>'UN','ncm'=>'61091000',
            'tax_data'=>array_replace(['icms_csosn'=>'102','pis_cst'=>'49','pis_rate'=>'0',
                'cofins_cst'=>'49','cofins_rate'=>'0','ipi_cst'=>'53','ipi_enq'=>'999'], $additionalTax),
        ])]));
        return [$draft, $company];
    }

    private function ini(NfeDraft $draft, CompanySetting $company): array
    {
        return parse_ini_string(app(Nfe55IniBuilder::class)->build($draft, $company, 24, 12345678), true, INI_SCANNER_RAW);
    }


    private function configureAccountant(string $document='11.222.333/0001-81'): void
    {
        \App\Models\AppSetting::put('accounting','accountant_document',$document);
        \App\Models\AppSetting::put('accounting','accountant_xml_authorized',true);
    }

    public function test_explicit_accountant_authorization_reaches_real_acbr_ini_first(): void
    {
        $this->configureAccountant();
        \App\Models\AppSetting::put('accounting','nfe_ba_sefaz_xml_authorized',true);
        [$draft,$company]=$this->invoice([]);
        $ini=$this->ini($draft,$company);
        self::assertSame('11222333000181',$ini['autXML001']['CNPJCPF']);
        self::assertArrayNotHasKey('autXML002',$ini);
        self::assertArrayNotHasKey('autXML003',$ini);
        app(\App\Services\Fiscal\Nfe55AuthorizedXmlService::class)->assertRequired($draft,$company);
    }

    public function test_registration_alone_does_not_authorize_xml_and_bahia_is_blocked_before_transmission(): void
    {
        \App\Models\AppSetting::put('accounting','accountant_document','11222333000181');
        [$draft,$company]=$this->invoice([]);
        self::assertArrayNotHasKey('autXML001',$this->ini($draft,$company));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('486');
        app(\App\Services\Fiscal\Nfe55AuthorizedXmlService::class)->assertRequired($draft,$company);
    }

    public function test_sefaz_bahia_alternative_requires_explicit_selection_and_is_limited_to_bahia(): void
    {
        [$draft,$company]=$this->invoice([]);
        \App\Models\AppSetting::put('accounting','nfe_ba_sefaz_xml_authorized',true);
        self::assertSame('13937073000156',$this->ini($draft,$company)['autXML001']['CNPJCPF']);
        $company->state='SP';
        self::assertSame([],app(\App\Services\Fiscal\Nfe55AuthorizedXmlService::class)->documents($draft,$company));
    }

    public function test_authorized_cpf_and_legacy_invalid_identifiers_are_checked_before_native_emission(): void
    {
        $this->configureAccountant('529.982.247-25');
        [$draft,$company]=$this->invoice([]);
        self::assertSame('52998224725',$this->ini($draft,$company)['autXML001']['CNPJCPF']);
        $this->configureAccountant('11111111111111');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CPF/CNPJ válido');
        $this->ini($draft,$company);
    }

    public function test_recipient_cannot_be_repeated_as_a_third_party_authorized_for_xml(): void
    {
        $this->configureAccountant();
        [$draft,$company]=$this->invoice([]);
        $draft->recipient_snapshot=array_replace($draft->recipient_snapshot,['document'=>'11222333000181']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('destinatário já tem acesso');
        $this->ini($draft,$company);
    }
}
