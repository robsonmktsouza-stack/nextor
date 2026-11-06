<?php

namespace Tests\Unit;

use App\Models\CompanySetting;
use App\Models\NfeDraft;
use App\Models\NfeDraftItem;
use App\Services\Fiscal\Nfe55ForeignTradeService;
use Tests\TestCase;
use RuntimeException;

final class Nfe55ForeignTradeServiceTest extends TestCase
{
    private function item(string $kind, array $extra = []): NfeDraftItem
    {
        return new NfeDraftItem(array_replace([
            'item_number'=>1,'cfop'=>$kind==='import'?'3102':'7102','quantity'=>'1.0000',
            'tax_data'=>[
                'icms_cst'=>'00','icms_base'=>'150.00','icms_rate'=>'18.0000',
                'ipi_cst'=>'00','ipi_base'=>'110.00','ipi_rate'=>'5.0000',
                'pis_cst'=>'01','pis_base'=>'100.00','pis_rate'=>'1.0000',
                'cofins_cst'=>'01','cofins_base'=>'100.00','cofins_rate'=>'3.0000',
                'fiscal_rule_trace'=>['id'=>1,'revision'=>1,'hash'=>str_repeat('a',64),
                    'legal_source'=>'https://www.gov.br/receitafederal/',
                    'snapshot'=>['fiscal_operation'=>$kind]],
            ],
            'special_data'=>$kind==='import'?$this->importData():[],
        ],$extra));
    }

    private function importData(): array
    {
        return [
            'import_taxes'=>['base'=>'100.00','rate'=>'10.0000','customs_expenses'=>'5.00','iof_amount'=>'1.00'],
            'import_declarations'=>[[
                'number'=>'2612345678','date'=>'2026-10-20','clearance_location'=>'PORTO DE SANTOS',
                'clearance_uf'=>'SP','clearance_date'=>'2026-10-22','transport_type'=>'1',
                'afrmm_value'=>'0.00','intermediation_type'=>'1','exporter_code'=>'EXPORTER-1',
                'additions'=>[['number'=>1,'sequence'=>1,'manufacturer_code'=>'MANUFACTURER-1','discount'=>'0.00']],
            ]],
        ];
    }

    private function draft(string $kind): NfeDraft
    {
        $draft = new NfeDraft([
            'destination'=>'foreign','purpose'=>'normal','operation_type'=>$kind==='import'?'inbound':'outbound',
            'issue_date'=>'2026-10-25','recipient_snapshot'=>[
                'state'=>'EX','country_code'=>'2496','country_name'=>'ESTADOS UNIDOS','foreign_id'=>'FOREIGN-123',
            ],
            'custom_fields'=>['fiscal_operation'=>$kind,'foreign_trade'=>$kind==='export'?[
                'export_exit_uf'=>'SP','export_exit_location'=>'PORTO DE SANTOS','export_dispatch_location'=>'SANTOS',
            ]:[]],
        ]);
        $draft->setRelation('items',collect([$this->item($kind)]));
        return $draft;
    }

    private function validate(NfeDraft $draft, string $crt = '3'): array
    {
        return (new Nfe55ForeignTradeService)->validate($draft,new CompanySetting(['crt'=>$crt]));
    }

    public function test_export_serializes_actual_exit_location_without_inventing_country_or_duties(): void
    {
        $result=$this->validate($this->draft('export'));
        self::assertSame('export',$result['kind']);
        self::assertSame(0,$result['ii_total_cents']);
        self::assertSame([['name'=>'Exporta','fields'=>[
            'UFSaidaPais'=>'SP','xLocExporta'=>'PORTO DE SANTOS','xLocDespacho'=>'SANTOS',
        ]]],$result['sections']);
    }

    public function test_direct_import_serializes_di_addition_and_integer_cent_import_duty(): void
    {
        $result=$this->validate($this->draft('import'));
        self::assertSame(1000,$result['ii_total_cents']);
        self::assertSame([1=>1000],$result['item_ii_cents']);
        $sections=array_column($result['sections'],'fields','name');
        self::assertSame(['vBC'=>'100.00','vDespAdu'=>'5.00','vII'=>'10.00','vIOF'=>'1.00'],$sections['II001']);
        self::assertSame('20/10/2026',$sections['DI001001']['dDi']);
        self::assertSame('1',$sections['DI001001']['tpIntermedio']);
        self::assertArrayNotHasKey('CNPJ',$sections['DI001001']);
        self::assertSame('MANUFACTURER-1',$sections['LADI001001001']['cFabricante']);
        self::assertSame('0.00',$sections['DI001001']['vAFRMM']);
    }

    public function test_import_duty_rounds_half_up_and_requires_explicit_zeroes(): void
    {
        $service=new Nfe55ForeignTradeService;
        $tax=$service->calculateImportTaxes(['import_taxes'=>[
            'base'=>'0.05','rate'=>'10.0000','customs_expenses'=>'0.00','iof_amount'=>'0.00',
        ]]);
        self::assertSame(1,$tax['ii_amount']);
        $this->expectException(RuntimeException::class);
        $service->calculateImportTaxes(['import_taxes'=>['base'=>'100.00','rate'=>'0']]);
    }

    public function test_missing_or_inconsistent_duty_parameters_are_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('diverge');
        (new Nfe55ForeignTradeService)->calculateImportTaxes(['import_taxes'=>[
            'base'=>'100.00','rate'=>'10','ii_amount'=>'9.99','customs_expenses'=>'0','iof_amount'=>'0',
        ]]);
    }

    public function test_import_cannot_guess_base_composition_of_other_taxes(): void
    {
        $draft=$this->draft('import');$item=$draft->items->first();$tax=$item->tax_data;
        unset($tax['icms_base']);$item->tax_data=$tax;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('base expressamente aprovada de ICMS');
        $this->validate($draft);
    }

    public function test_third_party_import_is_blocked_instead_of_silently_omitting_party(): void
    {
        $draft=$this->draft('import');$item=$draft->items->first();$special=$item->special_data;
        $special['import_declarations'][0]['intermediation_type']='2';
        $special['import_declarations'][0]['third_party_cnpj']='12345678000199';$item->special_data=$special;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('conta e ordem');
        $this->validate($draft);
    }

    public function test_duimp_requires_its_own_layout(): void
    {
        $draft=$this->draft('import');$item=$draft->items->first();$special=$item->special_data;
        $special['import_declarations'][0]['number']='202600000000000';$item->special_data=$special;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DUIMP');
        $this->validate($draft);
    }

    public function test_di_dates_and_addition_uniqueness_are_validated(): void
    {
        $draft=$this->draft('import');$item=$draft->items->first();$special=$item->special_data;
        $special['import_declarations'][0]['additions'][]=$special['import_declarations'][0]['additions'][0];$item->special_data=$special;
        try {$this->validate($draft);self::fail('Duplicate additions must not be emitted.');}
        catch(RuntimeException $error){self::assertStringContainsString('duplicados',$error->getMessage());}
        $special['import_declarations'][0]['additions']=[$special['import_declarations'][0]['additions'][0]];
        $special['import_declarations'][0]['clearance_date']='2026-10-26';$item->special_data=$special;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sequência das datas');
        $this->validate($draft);
    }

    public function test_foreign_operation_requires_specific_approved_rule_and_real_foreign_recipient(): void
    {
        $draft=$this->draft('export');$item=$draft->items->first();$tax=$item->tax_data;
        $tax['fiscal_rule_trace']['snapshot']['fiscal_operation']='sale';$item->tax_data=$tax;
        try {$this->validate($draft);self::fail('A general sale rule cannot approve an export.');}
        catch(RuntimeException $error){self::assertStringContainsString('regra específica',$error->getMessage());}
        $draft->setRelation('items',collect([$this->item('export')]));
        $recipient=$draft->recipient_snapshot;$recipient['country_code']='1058';$draft->recipient_snapshot=$recipient;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Destinatário estrangeiro');
        $this->validate($draft);
    }

    public function test_simplified_regime_import_is_blocked_until_import_icms_is_implemented(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fora do DAS');
        $this->validate($this->draft('import'),'1');
    }

    public function test_national_invoice_cannot_carry_an_unserialized_foreign_group(): void
    {
        $draft=$this->draft('import');$draft->destination='internal';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('operação nacional');
        $this->validate($draft);
    }
}
