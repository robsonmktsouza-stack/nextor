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

final class Nfe55ResidualTaxIniTest extends TestCase
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


    private function applyRule(NfeDraft $draft, CompanySetting $company, array $parameters): void
    {
        \App\Models\FiscalTaxRule::query()->create([
            'name'=>'Residual explicit fixture','document_type'=>'nfe','origin_uf'=>'BA','destination_uf'=>'BA',
            'crt'=>$company->crt,'cfop'=>'5102','operation_type'=>'outbound','purpose'=>'normal',
            'csosn'=>$parameters['icms_csosn']??'', 'icms_cst'=>$parameters['icms_cst']??'',
            'pis_cst'=>'49','cofins_cst'=>'49',
            'valid_from'=>'2025-01-01','valid_until'=>'2026-12-31','priority'=>0,'is_active'=>true,
            'legal_source'=>'https://www.confaz.fazenda.gov.br/legislacao',
            'legal_reference'=>'Fixture aritmética; não constitui enquadramento fiscal',
            'layout_version'=>'NF-e 4.00 fixture','consulted_at'=>'2026-10-25',
            'tax_parameters'=>$parameters,
        ]);
        app(\App\Services\Fiscal\Nfe55TaxRuleApplicationService::class)->apply($draft,$company,false);
    }

    public function test_cst_90_own_and_st_reach_the_real_builder_and_reconcile_totals(): void
    {
        [$draft,$company]=$this->invoice([],110);
        $company->crt='3';$draft->issue_date='2025-12-01';
        $draft->items[0]->tax_data=['pis_cst'=>'49','pis_rate'=>'0','cofins_cst'=>'49','cofins_rate'=>'0','ipi_cst'=>'53','ipi_enq'=>'999'];
        $this->applyRule($draft,$company,['icms_cst'=>'90','icms_base'=>'100','icms_rate'=>'18','icms_base_mode'=>'3',
            'icms_st_base_mode'=>'4','icms_st_base'=>'100','icms_st_mva'=>'40','icms_st_rate'=>'18',
            'icms_st_deduction'=>'18','fcp_st_rate'=>'2']);
        $ini=$this->ini($draft,$company);
        self::assertSame('90',$ini['ICMS001']['CST']);
        self::assertSame('18.00',$ini['ICMS001']['vICMS']);
        self::assertSame('7.20',$ini['Total']['vST']);
        self::assertSame('2.80',$ini['Total']['vFCPST']);
        self::assertSame('110.00',$ini['Total']['vNF']);
    }

    public function test_900_without_credit_does_not_require_a_contributor_recipient(): void
    {
        [$draft,$company]=$this->invoice([]);
        $tax=$draft->items[0]->tax_data;unset($tax['icms_csosn']);$draft->items[0]->tax_data=$tax;
        $this->applyRule($draft,$company,['icms_csosn'=>'900','icms_base'=>'100','icms_rate'=>'0','icms_base_mode'=>'3']);
        $ini=$this->ini($draft,$company);
        self::assertSame('900',$ini['ICMS001']['CSOSN']);
        self::assertSame('0.00',$ini['ICMS001']['vICMS']);
        self::assertArrayNotHasKey('pCredSN',$ini['ICMS001']);
        self::assertSame('100.00',$ini['Total']['vNF']);
    }

    public function test_900_credit_is_serialized_only_with_explicit_base_and_contributor(): void
    {
        [$draft,$company]=$this->invoice([]);
        $draft->recipient_snapshot=array_replace($draft->recipient_snapshot,[
            'ie_indicator'=>'contributor','state_registration'=>'12345678']);
        $tax=$draft->items[0]->tax_data;unset($tax['icms_csosn']);$draft->items[0]->tax_data=$tax;
        $this->applyRule($draft,$company,['icms_csosn'=>'900','icms_base'=>'100','icms_credit_rate'=>'3.1234']);
        $ini=$this->ini($draft,$company);
        self::assertSame('3.12',$ini['ICMS001']['vCredICMSSN']);
        self::assertArrayNotHasKey('pICMS',$ini['ICMS001']);
        self::assertSame('0.00',$ini['Total']['vICMS']);
    }
}
