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

final class Nfe55AdditionalFederalIntegrationTest extends TestCase
{
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

    private function preview(NfeDraft $draft): array
    {
        return app(Nfe55DraftTotalsService::class)->calculate($draft->items->map->toArray()->all(), [
            'issue_date'=>$draft->issue_date->toDateString(),'crt'=>'1','operation_type'=>$draft->operation_type,
            'purpose'=>$draft->purpose,'fiscal_operation'=>$draft->purpose==='return'?'devolution':'sale',
        ]);
    }

    public function test_each_st_indicator_changes_the_invoice_total_only_when_explicitly_enabled(): void
    {
        foreach ([['0','0',100.0], ['1','0',101.65], ['0','1',107.6], ['1','1',109.25]] as [$pisAdd, $cofinsAdd, $expected]) {
            [$draft, $company] = $this->invoice([
                'pis_st_calculation_type'=>'percent','pis_st_base'=>'100.00','pis_st_rate'=>'1.65','pis_st_add_to_total'=>$pisAdd,
                'cofins_st_calculation_type'=>'percent','cofins_st_base'=>'100.00','cofins_st_rate'=>'7.6','cofins_st_add_to_total'=>$cofinsAdd,
            ], $expected);
            $ini = $this->ini($draft, $company);
            $preview = $this->preview($draft);
            self::assertSame('1.65', $ini['PISST001']['vPIS']);
            self::assertSame('7.60', $ini['COFINSST001']['vCOFINS']);
            self::assertSame($pisAdd, $ini['PISST001']['indSomaPISST']);
            self::assertSame($cofinsAdd, $ini['COFINSST001']['indSomaCOFINSST']);
            self::assertSame('0.00', $ini['Total']['vPIS']);
            self::assertSame('0.00', $ini['Total']['vCOFINS']);
            self::assertSame(number_format($expected, 2, '.', ''), $ini['Total']['vNF']);
            self::assertSame(1.65, $preview['pis_st']);
            self::assertSame(7.6, $preview['cofins_st']);
            self::assertSame($expected, $preview['total']);
            self::assertSame(number_format($expected - 100, 2, '.', ''), number_format($preview['federal_st_addition'], 2, '.', ''));
            self::assertTrue($preview['tax_calculation_complete'], implode('; ', $preview['tax_errors']));
        }
    }

    public function test_quantity_st_rounds_the_same_cent_in_preview_and_emitted_ini(): void
    {
        [$draft, $company] = $this->invoice(['pis_st_calculation_type'=>'quantity',
            'pis_st_quantity'=>'2.0000','pis_st_unit_rate'=>'0.0025','pis_st_add_to_total'=>'1'], 100.01);
        $ini = $this->ini($draft, $company);
        $preview = $this->preview($draft);
        self::assertSame('0.01', $ini['PISST001']['vPIS']);
        self::assertSame('2.0000', $ini['PISST001']['qBCProd']);
        self::assertArrayNotHasKey('vBC', $ini['PISST001']);
        self::assertSame('100.01', $ini['Total']['vNF']);
        self::assertSame(0.01, $preview['pis_st']);
        self::assertSame(100.01, $preview['total']);
        self::assertTrue($preview['tax_calculation_complete'], implode('; ', $preview['tax_errors']));
    }

    public function test_returned_ipi_uses_the_origin_item_amount_and_reconciles_return_total(): void
    {
        [$draft, $company] = $this->invoice(['ipi_returned_percentage'=>'50', 'ipi_returned_original_amount'=>'10'], 105.0);
        $draft->purpose = 'return';
        $draft->operationNature->purpose = 'return';
        $draft->operationNature->name = 'DEVOLUCAO';
        $draft->items->first()->cfop = '5202';
        $draft->payment_type = '90';
        $draft->payments = [['type'=>'90','amount'=>0]];
        $draft->has_referenced_document = true;
        $draft->references = [['type'=>'nfe','key'=>self::REFERENCE]];
        $draft->items->first()->special_data = [
            'dfe_reference'=>['key'=>self::REFERENCE,'n_item'=>1],
        ];
        $ini = $this->ini($draft, $company);
        $preview = $this->preview($draft);
        self::assertSame('4', $ini['Identificacao']['finNFe']);
        self::assertSame('5202', $ini['Produto001']['CFOP']);
        self::assertSame('50.00', $ini['impostoDevol001']['pDevol']);
        self::assertSame('5.00', $ini['impostoDevol001']['vIPIDevol']);
        self::assertSame('5.00', $ini['Total']['vIPIDevol']);
        self::assertSame('0.00', $ini['Total']['vIPI']);
        self::assertSame('105.00', $ini['Total']['vNF']);
        self::assertSame(self::REFERENCE, $ini['DFeReferenciado001']['chaveAcesso']);
        self::assertSame('1', $ini['DFeReferenciado001']['nItem']);
        self::assertArrayNotHasKey('NFRef001', $ini);
        self::assertSame('90', $ini['pag001']['tPag']);
        self::assertSame(5.0, $preview['ipi_returned']);
        self::assertSame(105.0, $preview['total']);
        self::assertTrue($preview['tax_calculation_complete'], implode('; ', $preview['tax_errors']));
    }

    public function test_normal_sale_cannot_emit_returned_ipi(): void
    {
        [$draft, $company] = $this->invoice(['ipi_returned_percentage'=>'50', 'ipi_returned_original_amount'=>'10'], 105.0);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('IPI devolvido exige finalidade de devolução');
        $this->ini($draft, $company);
    }

    public function test_normal_sale_preview_marks_returned_ipi_as_pending(): void
    {
        [$draft] = $this->invoice(['ipi_returned_percentage'=>'50', 'ipi_returned_original_amount'=>'10'], 105.0);
        $preview = $this->preview($draft);
        self::assertFalse($preview['tax_calculation_complete']);
        self::assertNull($preview['ipi_returned']);
        self::assertNotEmpty($preview['tax_errors']);
    }

    public function test_partial_renders_ids_only_for_the_item_editor_and_preserves_rule_zero_indicator(): void
    {
        $parameters = ['pis_st_calculation_type'=>'percent', 'pis_st_add_to_total'=>'0', 'ipi_returned_percentage'=>'50'];
        $editor = view('fiscal.nfe._additional-federal-taxes', ['ruleMode'=>false, 'federalParameters'=>$parameters])->render();
        $rule = view('fiscal.nfe._additional-federal-taxes', ['ruleMode'=>true, 'federalParameters'=>$parameters])->render();
        self::assertStringContainsString('id="nfeTaxPisStMode"', $editor);
        self::assertStringContainsString('id="nfeTaxCofinsStAdd"', $editor);
        self::assertStringContainsString('id="nfeTaxIpiReturnedOriginal"', $editor);
        self::assertStringNotContainsString('name="tax_parameters[', $editor);
        self::assertStringNotContainsString(' id=', $rule);
        self::assertStringContainsString('name="tax_parameters[pis_st_add_to_total]"', $rule);
        self::assertStringContainsString('value="0" selected', $rule);
    }
}
