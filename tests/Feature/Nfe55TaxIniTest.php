<?php

namespace Tests\Feature;

use App\Models\CompanySetting;
use App\Models\NfeDraft;
use App\Models\NfeDraftItem;
use App\Models\OperationNature;
use App\Services\Fiscal\Nfe55IniBuilder;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;
use Tests\TestCase;

final class Nfe55TaxIniTest extends TestCase
{
    private function invoice(array $tax, string $crt='3', string $date='2025-12-01', int $total=100): array
    {
        $company=new CompanySetting([
            'document'=>'12345678000100','legal_name'=>'EMITENTE TESTE',
            'state_registration'=>'12345678','state'=>'BA','city'=>'Salvador',
            'city_ibge_code'=>'2927408','address'=>'Rua Emitente','address_number'=>'10',
            'district'=>'Centro','zip_code'=>'40000000','crt'=>$crt,
        ]);
        $draft=new NfeDraft([
            'status'=>'draft','operation_type'=>'outbound','destination'=>'internal',
            'presence'=>'presential','purpose'=>'normal','issue_date'=>$date,'issue_time'=>'14:00',
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
        $draft->setRelation('operationNature',new OperationNature([
            'name'=>'VENDA','operation_type'=>'outbound','purpose'=>'normal','is_active'=>true,
        ]));
        $draft->setRelation('items',new Collection([new NfeDraftItem([
            'item_number'=>1,'product_name'=>'MERCADORIA','product_sku'=>'ITEM-001',
            'quantity'=>'2','dimension_quantity'=>'1','unit_price'=>'50',
            'cfop'=>'5102','origin'=>'0','unit'=>'UN','tax_unit'=>'UN','ncm'=>'61091000',
            'tax_data'=>$tax,
        ])]));
        return [$draft,$company];
    }

    private function ini(NfeDraft $draft,CompanySetting $company): array
    {
        return parse_ini_string(app(Nfe55IniBuilder::class)->build($draft,$company,24,12345678),true,INI_SCANNER_RAW);
    }

    public function test_builder_serializes_real_icms_fcp_and_taxable_ipi_into_total(): void
    {
        [$draft,$company]=$this->invoice([
            'icms_cst'=>'00','icms_rate'=>'18','icms_base'=>'100.00','fcp_rate'=>'2',
            'pis_cst'=>'01','pis_rate'=>'1.65','pis_base'=>'100','cofins_cst'=>'01','cofins_rate'=>'7.6','cofins_base'=>'100',
            'ipi_cst'=>'50','ipi_enq'=>'999','ipi_rate'=>'5',
        ],total:105);
        $ini=$this->ini($draft,$company);
        self::assertSame('18.00',$ini['ICMS001']['vICMS']);
        self::assertSame('2.00',$ini['Total']['vFCP']);
        self::assertSame('5.00',$ini['IPI001']['vIPI']);
        self::assertSame('1.65',$ini['Total']['vPIS']);
        self::assertSame('7.60',$ini['Total']['vCOFINS']);
        self::assertSame('105.00',$ini['Total']['vNF']);
    }

    public function test_builder_adds_st_and_fcp_st_to_invoice_without_adding_own_icms_twice(): void
    {
        [$draft,$company]=$this->invoice([
            'icms_cst'=>'10','icms_rate'=>'18','icms_st_base_mode'=>'4',
            'icms_st_base'=>'100','icms_st_mva'=>'40','icms_st_rate'=>'18',
            'icms_st_deduction'=>'18','fcp_st_rate'=>'2',
            'pis_cst'=>'49','pis_rate'=>'0','cofins_cst'=>'49','cofins_rate'=>'0','ipi_cst'=>'53','ipi_enq'=>'999',
        ],total:110);
        $ini=$this->ini($draft,$company);
        self::assertSame('140.00',$ini['Total']['vBCST']);
        self::assertSame('7.20',$ini['Total']['vST']);
        self::assertSame('2.80',$ini['Total']['vFCPST']);
        self::assertSame('110.00',$ini['Total']['vNF']);
    }

    public function test_rtc_uses_the_actual_legacy_taxes_and_allocated_item_values(): void
    {
        [$draft,$company]=$this->invoice([
            'icms_cst'=>'00','icms_rate'=>'18',
            'pis_cst'=>'01','pis_rate'=>'1.65','pis_base'=>'110','cofins_cst'=>'01','cofins_rate'=>'7.6','cofins_base'=>'110','ipi_cst'=>'53','ipi_enq'=>'999',
            'ibs_cbs_cst'=>'000','ibs_cbs_class'=>'000001',
            'ibs_uf_rate'=>'0.1','ibs_municipal_rate'=>'0','cbs_rate'=>'0.9',
        ],date:'2026-10-25',total:110);
        $draft->transport_data=['freight_value'=>10];
        $ini=$this->ini($draft,$company);
        // 110 - ICMS 19.80 - PIS 1.82 - COFINS 8.36 = 80.02.
        self::assertSame('80.02',$ini['gIBSCBS001']['vBC']);
        self::assertSame('0.08',$ini['gIBSUF001']['vIBSUF']);
        self::assertSame('0.72',$ini['gCBS001']['vCBS']);
        self::assertSame('110.00',$ini['Total']['vNF']);
    }

    public function test_current_regular_regime_cannot_omit_rtc_classification(): void
    {
        [$draft,$company]=$this->invoice([
            'icms_cst'=>'00','icms_rate'=>'18','pis_cst'=>'49','pis_rate'=>'0','cofins_cst'=>'49','cofins_rate'=>'0','ipi_cst'=>'53','ipi_enq'=>'999',
        ],date:'2026-10-25');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('classificação');
        $this->ini($draft,$company);
    }

    public function test_interstate_contributor_is_serialized_with_destination_uf_and_cfop(): void
    {
        [$draft,$company]=$this->invoice([
            'icms_csosn'=>'102','pis_cst'=>'49','pis_rate'=>'0','cofins_cst'=>'49','cofins_rate'=>'0','ipi_cst'=>'53','ipi_enq'=>'999',
        ],crt:'1');
        $recipient=$draft->recipient_snapshot;
        $recipient['state']='SP';$recipient['city_ibge_code']='3550308';
        $recipient['city']='Sao Paulo';$recipient['ie_indicator']='contributor';
        $recipient['state_registration']='123456789012';
        $draft->recipient_snapshot=$recipient;$draft->destination='interstate';
        $draft->items->first()->cfop='6102';
        $ini=$this->ini($draft,$company);
        self::assertSame('2',$ini['Identificacao']['idDest']);
        self::assertSame('SP',$ini['Destinatario']['UF']);
        self::assertSame('6102',$ini['Produto001']['CFOP']);
    }
}
