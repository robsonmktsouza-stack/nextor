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

final class Nfe55OwnProductionNonContributorIniTest extends TestCase
{
    /** Explicit calculation fixtures, never distributed as fiscal rules or rates. */
    private function invoice(): array
    {
        $company=new CompanySetting([
            'document'=>'12345678000100','legal_name'=>'EMITENTE TESTE',
            'state_registration'=>'12345678','state'=>'BA','city'=>'Salvador',
            'city_ibge_code'=>'2927408','address'=>'Rua Emitente','address_number'=>'10',
            'district'=>'Centro','zip_code'=>'40000000','crt'=>'1',
        ]);
        $draft=new NfeDraft([
            'status'=>'draft','operation_type'=>'outbound','destination'=>'interstate',
            'presence'=>'presential','purpose'=>'normal','issue_date'=>'2025-12-01','issue_time'=>'14:00',
            'series'=>1,'environment'=>'homologation','payment_type'=>'01','final_consumer'=>true,
            'recipient_snapshot'=>[
                'name'=>'DESTINATARIO TESTE','document'=>'12345678000190','state'=>'SP',
                'city'=>'São Paulo','city_ibge_code'=>'3550308','address'=>'Rua Destinatario',
                'address_number'=>'20','district'=>'Centro','zip_code'=>'01001000',
                'ie_indicator'=>'non_contributor',
            ],
            'totals'=>['products'=>100,'total'=>105],
            'payments'=>[['type'=>'01','amount'=>105,'condition'=>'a_vista']],
            'transport_data'=>['freight_value'=>0],
        ]);
        $draft->setRelation('operationNature',new OperationNature([
            'name'=>'Venda de produção própria — não contribuinte','operation_type'=>'outbound',
            'purpose'=>'normal','is_active'=>true,
        ]));
        $draft->setRelation('items',new Collection([new NfeDraftItem([
            'item_number'=>1,'product_name'=>'PRODUÇÃO PRÓPRIA TESTE','product_sku'=>'PROD-001',
            'quantity'=>'1','dimension_quantity'=>'1','unit_price'=>'100',
            'cfop'=>'6107','origin'=>'0','unit'=>'UN','tax_unit'=>'UN','ncm'=>'61091000',
            'tax_data'=>[
                'icms_csosn'=>'102','pis_cst'=>'49','pis_rate'=>'0','cofins_cst'=>'49','cofins_rate'=>'0',
                'ipi_cst'=>'50','ipi_enq'=>'999','ipi_rate'=>'5',
            ],
        ])]));
        return [$draft,$company];
    }

    private function ini(NfeDraft $draft,CompanySetting $company): array
    {
        return parse_ini_string(app(Nfe55IniBuilder::class)->build($draft,$company,24,12345678),true,INI_SCANNER_RAW);
    }

    public function test_simples_own_production_sale_to_interstate_noncontributor_serializes_real_ini_with_ipi(): void
    {
        [$draft,$company]=$this->invoice();
        $ini=$this->ini($draft,$company);
        self::assertSame('6107',$ini['Produto001']['CFOP']);
        self::assertSame('2',$ini['Identificacao']['idDest']);
        self::assertSame('9',$ini['Destinatario']['indIEDest']);
        self::assertSame('102',$ini['ICMS001']['CSOSN']);
        self::assertSame('50',$ini['IPI001']['CST']);
        self::assertSame('5.00',$ini['IPI001']['vIPI']);
        self::assertSame('105.00',$ini['Total']['vNF']);
        self::assertArrayNotHasKey('ICMSUFDest001',$ini);
    }

    public function test_cfop_6107_cannot_be_used_for_a_contributor(): void
    {
        [$draft,$company]=$this->invoice();
        $draft->recipient_snapshot=[...$draft->recipient_snapshot,'ie_indicator'=>'contributor','state_registration'=>'123456789012'];
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CFOP 6107 exige venda interestadual a destinatário não contribuinte');
        $this->ini($draft,$company);
    }

    public function test_domestic_5107_is_not_enabled_by_the_specific_interstate_treatment(): void
    {
        [$draft,$company]=$this->invoice();
        $draft->destination='internal';
        $draft->recipient_snapshot=[...$draft->recipient_snapshot,'state'=>'BA','city'=>'Salvador','city_ibge_code'=>'2927408'];
        $draft->items->first()->cfop='5107';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CFOP 6107 exige venda interestadual');
        $this->ini($draft,$company);
    }

    public function test_6107_does_not_bypass_difal_requirements_for_normal_regime(): void
    {
        [$draft,$company]=$this->invoice(); $company->crt='3';
        $draft->items->first()->tax_data=[
            'icms_cst'=>'00','icms_rate'=>'12','icms_base'=>'100',
            'pis_cst'=>'49','pis_rate'=>'0','cofins_cst'=>'49','cofins_rate'=>'0',
            'ipi_cst'=>'50','ipi_enq'=>'999','ipi_rate'=>'5',
        ];
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DIFAL: informe bases, alíquotas e partilha');
        $this->ini($draft,$company);
    }
}
