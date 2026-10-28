<?php

namespace Tests\Feature;

use App\Models\CompanySetting;
use App\Models\FiscalTaxRule;
use App\Models\NfeDraft;
use App\Models\OperationNature;
use App\Services\Fiscal\Nfe55IniBuilder;
use App\Services\Fiscal\Nfe55TaxRuleApplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/** Synthetic fiscal inputs exercise the actual rule -> item -> INI flow, not SEFAZ homologation. */
final class Nfe55ForeignTradeIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function invoice(string $kind): array
    {
        $export = $kind === 'export';
        $company = CompanySetting::current();
        $company->update([
            'document'=>'12345678000100','legal_name'=>'EMITENTE DE TESTES',
            'state_registration'=>'12345678','state'=>'BA','city'=>'Salvador','city_ibge_code'=>'2927408',
            'address'=>'Rua de Testes','address_number'=>'10','district'=>'Centro','zip_code'=>'40000000',
            'crt'=>$export?'1':'3',
        ]);
        $tax = $export ? ['ipi_cst'=>'53','ipi_enq'=>'999'] : [
            'icms_rate'=>'18','icms_base'=>'150.00','ipi_cst'=>'00','ipi_enq'=>'999','ipi_rate'=>'5','ipi_base'=>'110.00',
            'pis_rate'=>'1','pis_base'=>'100.00','cofins_rate'=>'3','cofins_base'=>'100.00',
        ];
        $rule = FiscalTaxRule::query()->create([
            'name'=>'Regra sintética '.$kind,'document_type'=>'nfe','origin_uf'=>'BA','destination_uf'=>'EX',
            'crt'=>$company->crt,'cfop'=>$export?'7102':'3102','csosn'=>$export?'300':'',
            'icms_cst'=>$export?null:'00','pis_cst'=>$export?'08':'01','cofins_cst'=>$export?'08':'01',
            'operation_type'=>$export?'outbound':'inbound','fiscal_operation'=>$kind,
            'valid_from'=>'2025-01-01','valid_until'=>'2026-12-31','priority'=>1,'is_active'=>true,
            'tax_parameters'=>$tax,'legal_source'=>'https://www.confaz.fazenda.gov.br/legislacao',
            'legal_reference'=>'Fixture sintética; não constitui parametrização fiscal para produção',
            'layout_version'=>'NF-e 4.00','consulted_at'=>'2025-11-01',
        ]);
        $nature = OperationNature::query()->create([
            'name'=>$export?'EXPORTACAO DIRETA':'IMPORTACAO DIRETA',
            'operation_type'=>$export?'outbound':'inbound','purpose'=>'normal','is_active'=>true,
        ]);
        $total = $export ? 100 : 121.50;
        $draft = NfeDraft::query()->create([
            'operation_nature_id'=>$nature->id,'status'=>'draft','operation_type'=>$nature->operation_type,
            'destination'=>'foreign','presence'=>'not_applicable','purpose'=>'normal',
            'issue_date'=>$export?'2026-10-25':'2025-11-25','issue_time'=>'14:00',
            'series'=>1,'environment'=>'homologation','payment_type'=>'01',
            'recipient_snapshot'=>[
                'name'=>'FOREIGN RECIPIENT TEST','state'=>'EX','city'=>'EXTERIOR','city_ibge_code'=>'9999999',
                'foreign_id'=>'FOREIGN-123','country_code'=>'2496','country_name'=>'ESTADOS UNIDOS',
                'address'=>'TEST STREET','address_number'=>'20','district'=>'CENTRAL',
                'ie_indicator'=>'non_contributor',
            ],
            'custom_fields'=>['fiscal_operation'=>$kind,'foreign_trade'=>$export?[
                'export_exit_uf'=>'SP','export_exit_location'=>'PORTO DE SANTOS',
            ]:[]],
            'totals'=>['products'=>100,'total'=>$total],
            'payments'=>[['type'=>'01','amount'=>$total,'condition'=>'a_vista']],
            'transport_data'=>['freight_value'=>0,'freight_mode'=>'9'],
        ]);
        $draft->items()->create([
            'item_number'=>1,'product_name'=>'MERCADORIA DE TESTE','product_sku'=>'TEST-1',
            'quantity'=>'1','dimension_quantity'=>'1','unit_price'=>'100','unit'=>'UN','tax_unit'=>'UN',
            'cfop'=>$rule->cfop,'origin'=>$export?'0':'1','ncm'=>'61091000',
            'other_expenses'=>$export?0:6,
            'tax_data'=>[],
            'special_data'=>$export?[]:[
                'import_taxes'=>['base'=>'100.00','rate'=>'10','customs_expenses'=>'5.00','iof_amount'=>'1.00'],
                'import_declarations'=>[[
                    'number'=>'2512345678','date'=>'2025-11-20','clearance_location'=>'PORTO DE SANTOS',
                    'clearance_uf'=>'SP','clearance_date'=>'2025-11-22','transport_type'=>'1',
                    'afrmm_value'=>'0.00','intermediation_type'=>'1','exporter_code'=>'EXPORTER-1',
                    'additions'=>[['number'=>1,'sequence'=>1,'manufacturer_code'=>'MANUFACTURER-1']],
                ]],
            ],
        ]);
        app(Nfe55TaxRuleApplicationService::class)->apply($draft,$company);
        return [$draft->fresh(['items','operationNature']),$company,$rule];
    }

    public function test_direct_export_flows_from_approved_rule_to_foreign_recipient_and_export_group(): void
    {
        [$draft,$company]=$this->invoice('export');
        $ini=parse_ini_string(app(Nfe55IniBuilder::class)->build($draft,$company,1,12345678),true,INI_SCANNER_RAW);
        self::assertSame('3',$ini['Identificacao']['idDest']);
        self::assertSame('1',$ini['Identificacao']['tpNF']);
        self::assertSame('FOREIGN-123',$ini['Destinatario']['idEstrangeiro']);
        self::assertSame('EX',$ini['Destinatario']['UF']);
        self::assertSame('2496',$ini['Destinatario']['cPais']);
        self::assertSame('SP',$ini['Exporta']['UFSaidaPais']);
        self::assertSame('7102',$ini['Produto001']['CFOP']);
        self::assertSame('100.00',$ini['Total']['vNF']);
        self::assertArrayNotHasKey('II001',$ini);
    }

    public function test_direct_import_keeps_di_additions_and_adds_actual_duty_once_to_total(): void
    {
        [$draft,$company]=$this->invoice('import');
        $ini=parse_ini_string(app(Nfe55IniBuilder::class)->build($draft,$company,2,12345678),true,INI_SCANNER_RAW);
        self::assertSame('0',$ini['Identificacao']['tpNF']);
        self::assertSame('3102',$ini['Produto001']['CFOP']);
        self::assertSame('2512345678',$ini['DI001001']['nDi']);
        self::assertSame('MANUFACTURER-1',$ini['LADI001001001']['cFabricante']);
        self::assertSame('10.00',$ini['II001']['vII']);
        self::assertSame('10.00',$ini['Total']['vII']);
        self::assertSame('5.50',$ini['Total']['vIPI']);
        self::assertSame('6.00',$ini['Total']['vOutro']);
        self::assertSame('121.50',$ini['Total']['vNF']);
    }

    public function test_source_rule_change_blocks_real_builder_until_document_is_reviewed_again(): void
    {
        [$draft,$company,$rule]=$this->invoice('export');
        $rule->update(['is_active'=>false]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Revalide');
        app(Nfe55IniBuilder::class)->build($draft,$company,1,12345678);
    }

    public function test_2026_import_uses_the_explicit_final_rtc_base_once_with_reviewed_rule_parameters(): void
    {
        [$draft,$company,$rule]=$this->invoice('import');
        $rule->update(['revision'=>2,'tax_parameters'=>array_replace($rule->tax_parameters,[
            'ibs_cbs_cst'=>'000','ibs_cbs_class'=>'000001','ibs_cbs_base'=>'150.00',
            'ibs_uf_rate'=>'0.1000','ibs_municipal_rate'=>'0.0000','cbs_rate'=>'0.9000',
        ])]);
        $draft->update(['issue_date'=>'2026-10-25']);
        $item=$draft->items()->first();$special=$item->special_data;
        $special['import_declarations'][0]['number']='2612345678';
        $special['import_declarations'][0]['date']='2026-10-20';
        $special['import_declarations'][0]['clearance_date']='2026-10-22';
        $item->update(['special_data'=>$special]);
        app(Nfe55TaxRuleApplicationService::class)->apply($draft,$company);
        $ini=parse_ini_string(app(Nfe55IniBuilder::class)->build($draft->fresh(['items','operationNature']),$company,3,12345678),true,INI_SCANNER_RAW);
        self::assertSame('150.00',$ini['gIBSCBS001']['vBC']);
        self::assertSame('0.15',$ini['gIBSUF001']['vIBSUF']);
        self::assertSame('1.35',$ini['gCBS001']['vCBS']);
        self::assertSame('121.50',$ini['Total']['vNF']);
    }
}
