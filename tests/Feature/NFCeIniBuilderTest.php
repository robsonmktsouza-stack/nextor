<?php

namespace Tests\Feature;

use App\Models\CompanySetting;
use App\Models\FiscalDocumentJob;
use App\Services\Fiscal\NFCeIniBuilder;
use Tests\TestCase;

final class NFCeIniBuilderTest extends TestCase
{
    public function test_it_builds_a_bahia_nfce_from_an_existing_pdv_snapshot(): void
    {
        $company = new CompanySetting([
            'document' => '39323356000100',
            'legal_name' => 'EMPRESA EXEMPLO LTDA',
            'state_registration' => '172036473',
            'crt' => '1',
            'address' => 'RUA EXEMPLO',
            'address_number' => '100',
            'district' => 'CENTRO',
            'city' => 'Rio do Antônio',
            'city_ibge_code' => '2926806',
            'state' => 'BA',
            'zip_code' => '46220000',
        ]);

        $document = new FiscalDocumentJob([
            'document_type' => 'nfce',
            'environment' => 'homologation',
            'series' => 1,
            'document_number' => 10,
            'source_snapshot' => [
                'total' => '10.00',
                'items' => [[
                    'item_type' => 'product',
                    'product_id' => 5,
                    'sku' => 'PROD-5',
                    'unit' => 'UN',
                    'name' => 'PRODUTO FICTICIO',
                    'ncm' => '6913.90.00',
                    'origin' => '0',
                    'quantity' => '1.000',
                    'unit_price' => '10.00',
                    'line_total' => '10.00',
                    'discount' => '0.00',
                    'tax_defaults' => [
                        'cfop_outbound_internal' => '5102',
                        'icms_csosn' => '102',
                        'pis_cst' => '49',
                        'cofins_cst' => '49',
                    ],
                ]],
                'payments' => [
                    ['payment_method' => 'dinheiro', 'payment_kind' => 'cash', 'amount' => '10.00'],
                ],
                'change_amount' => '0.00',
            ],
        ]);

        $ini = app(NFCeIniBuilder::class)->build($document, $company);

        self::assertStringContainsString('[Identificacao]', $ini);
        self::assertStringContainsString('cUF=29', $ini);
        self::assertStringContainsString('dhEmi=', $ini);
        self::assertStringContainsString('orig=0', $ini);
        self::assertStringNotContainsString('dEmi=', $ini);
        self::assertStringContainsString('Modelo=65', $ini);
        self::assertStringContainsString('tpAmb=2', $ini);
        self::assertStringContainsString('[Produto001]', $ini);
        self::assertStringContainsString('CFOP=5102', $ini);
        self::assertStringContainsString('NCM=69139000', $ini);
        self::assertStringContainsString('CSOSN=102', $ini);
        self::assertStringContainsString('[pag001]', $ini);
        self::assertStringContainsString('tPag=01', $ini);
        self::assertStringContainsString('vNF=10.00', $ini);
        self::assertStringNotContainsString('Senha', $ini);
        self::assertStringNotContainsString('CSC', $ini);
    }

    public function test_offline_ini_keeps_signed_contingency_fields_for_later_same_key(): void
    {
        $company=new CompanySetting([
            'document'=>'39323356000100','legal_name'=>'EMPRESA TESTE',
            'state_registration'=>'172036473','crt'=>'1',
            'address'=>'RUA EXEMPLO','address_number'=>'1','district'=>'CENTRO',
            'city'=>'Rio do Antonio','city_ibge_code'=>'2926806',
            'state'=>'BA','zip_code'=>'46220000',
            'timezone'=>'America/Bahia',
        ]);
        $job=new FiscalDocumentJob([
            'document_type'=>'nfce','emission_mode'=>'offline',
            'environment'=>'homologation','series'=>1,'document_number'=>11,
            'contingency_reason'=>'Sem comunicação com o ambiente autorizador.',
            'contingency_started_at'=>'2026-10-09T10:00:00-03:00',
            'source_snapshot'=>[
                'total'=>'10.00','items'=>[[
                    'item_type'=>'product','product_id'=>1,
                    'sku'=>'TESTE','name'=>'MERCADORIA','unit'=>'UN',
                    'origin'=>'0','ncm'=>'22021000','quantity'=>'1.000',
                    'unit_price'=>'10.00','line_total'=>'10.00','discount'=>'0.00',
                    'tax_defaults'=>[
                        'cfop_outbound_internal'=>'5102','icms_csosn'=>'102',
                        'pis_cst'=>'49','cofins_cst'=>'49',
                    ],
                ]],
                'payments'=>[['payment_method'=>'dinheiro','payment_kind'=>'cash','amount'=>'10.00']],
                'change_amount'=>'0.00',
            ],
        ]);
        $ini=app(NFCeIniBuilder::class)->build($job,$company);
        self::assertStringContainsString('tpEmis=9',$ini);
        self::assertStringContainsString('dhCont=09/10/2026 10:00:00',$ini);
        self::assertStringContainsString('xJust=Sem comunicação com o ambiente autorizador.',$ini);
        $job->emission_mode='normal';
        $normal=app(NFCeIniBuilder::class)->build($job,$company);
        self::assertStringContainsString('tpEmis=1',$normal);
        self::assertStringNotContainsString('dhCont=',$normal);
        self::assertStringNotContainsString('xJust=',$normal);
    }

    public function test_homologation_changes_only_first_product_description_but_keeps_all_codes(): void
    {
        $company = new CompanySetting([
            'document'=>'39323356000100',
            'legal_name'=>'EMPRESA DE TESTE LTDA',
            'state_registration'=>'172036473',
            'crt'=>'1',
            'address'=>'RUA EXEMPLO',
            'address_number'=>'100',
            'district'=>'CENTRO',
            'city'=>'Rio do Antonio',
            'city_ibge_code'=>'2926806',
            'state'=>'BA',
            'zip_code'=>'46220000',
        ]);
        $item=[
            'item_type'=>'product',
            'product_id'=>1,
            'sku'=>'COD-01',
            'name'=>'COCA COLA 2L',
            'unit'=>'UN',
            'ncm'=>'22021000',
            'origin'=>'0',
            'quantity'=>'1.000',
            'unit_price'=>'10.00',
            'line_total'=>'10.00',
            'discount'=>'0.00',
            'tax_defaults'=>[
                'cfop_outbound_internal'=>'5102',
                'icms_csosn'=>'102',
                'pis_cst'=>'49',
                'cofins_cst'=>'49',
            ],
        ];
        $second=array_replace($item,[
            'product_id'=>2,
            'sku'=>'COD-02',
            'name'=>'AGUA MINERAL 500ML',
        ]);
        $document=new FiscalDocumentJob([
            'document_type'=>'nfce',
            'environment'=>'homologation',
            'series'=>1,
            'document_number'=>33,
            'source_snapshot'=>[
                'total'=>'20.00',
                'items'=>[$item,$second],
                'payments'=>[[
                    'payment_method'=>'dinheiro',
                    'payment_kind'=>'cash',
                    'amount'=>'20.00',
                ]],
                'change_amount'=>'0.00',
            ],
        ]);

        $ini=app(NFCeIniBuilder::class)->build($document,$company);
        self::assertStringContainsString("cProd=COD-01\r\n",$ini);
        self::assertStringContainsString("cProd=COD-02\r\n",$ini);
        self::assertStringContainsString(
            'xProd=NOTA FISCAL EMITIDA EM AMBIENTE DE HOMOLOGACAO - SEM VALOR FISCAL',
            $ini
        );
        self::assertStringContainsString('xProd=AGUA MINERAL 500ML',$ini);
        self::assertStringNotContainsString('xProd=COCA COLA 2L',$ini);

        $document->environment='production';
        $production=app(NFCeIniBuilder::class)->build($document,$company);
        self::assertStringContainsString('xProd=COCA COLA 2L',$production);
        self::assertStringContainsString('xProd=AGUA MINERAL 500ML',$production);
        self::assertStringNotContainsString(
            'xProd=NOTA FISCAL EMITIDA EM AMBIENTE DE HOMOLOGACAO',
            $production
        );
    }

    public function test_split_payment_assigns_cash_change_only_to_cash_part(): void
    {
        $company = new CompanySetting([
            'document' => '39323356000100',
            'legal_name' => 'EMPRESA EXEMPLO LTDA',
            'state_registration' => '172036473',
            'crt' => '1',
            'address' => 'RUA EXEMPLO',
            'address_number' => '100',
            'district' => 'CENTRO',
            'city' => 'Rio do Antônio',
            'city_ibge_code' => '2926806',
            'state' => 'BA',
            'zip_code' => '46220000',
        ]);

        $document = new FiscalDocumentJob([
            'document_type' => 'nfce',
            'environment' => 'homologation',
            'series' => 1,
            'document_number' => 11,
            'source_snapshot' => [
                'total' => '10.00',
                'items' => [[
                    'item_type' => 'product',
                    'product_id' => 5,
                    'sku' => 'PROD-5',
                    'unit' => 'UN',
                    'name' => 'PRODUTO',
                    'ncm' => '22021000',
                    'origin' => '0',
                    'quantity' => '1.000',
                    'unit_price' => '10.00',
                    'line_total' => '10.00',
                    'discount' => '0.00',
                    'tax_defaults' => ['cfop_outbound_internal' => '5102', 'icms_csosn' => '102', 'pis_cst' => '49', 'cofins_cst' => '49'],
                ]],
                'payments' => [
                    ['payment_method' => 'pix', 'payment_kind' => 'pix', 'amount' => '4.00'],
                    ['payment_method' => 'dinheiro', 'payment_kind' => 'cash', 'amount' => '6.00'],
                ],
                'change_amount' => '4.00',
            ],
        ]);

        $ini = app(NFCeIniBuilder::class)->build($document, $company);
        self::assertStringContainsString('vNF=10.00', $ini);
        self::assertStringContainsString("[pag001]\r\ntPag=17\r\nvPag=4.00", $ini);
        self::assertStringContainsString("[pag002]\r\ntPag=01\r\nvPag=10.00\r\nvTroco=4.00", $ini);

        // Mesmo com o dinheiro antes do PIX, o vTroco deve estar na
        // última seção pag, pois a ACBr lê o valor como total do grupo.
        $snapshot = $document->source_snapshot;
        $snapshot['payments'] = array_reverse($snapshot['payments']);
        $document->source_snapshot = $snapshot;
        $ini = app(NFCeIniBuilder::class)->build($document, $company);
        self::assertStringContainsString("[pag001]\r\ntPag=01\r\nvPag=10.00", $ini);
        self::assertStringContainsString("[pag002]\r\ntPag=17\r\nvPag=4.00\r\nvTroco=4.00", $ini);
    }
    public function test_ini_emits_configured_pis_cofins_and_reconciles_totals(): void
    {
        $company = new CompanySetting([
            'document' => '39323356000100', 'legal_name' => 'EMPRESA EXEMPLO LTDA',
            'state_registration' => '172036473', 'crt' => '1',
            'address' => 'RUA EXEMPLO', 'address_number' => '100',
            'district' => 'CENTRO', 'city' => 'Rio do Antonio',
            'city_ibge_code' => '2926806', 'state' => 'BA', 'zip_code' => '46220000',
        ]);
        $document = new FiscalDocumentJob([
            'document_type' => 'nfce', 'environment' => 'homologation',
            'series' => 1, 'document_number' => 13,
            'source_snapshot' => [
                'total' => '90.00',
                'items' => [[
                    'item_type' => 'product', 'product_id' => 1,
                    'sku' => 'TESTE-PIS', 'unit' => 'UN', 'name' => 'PRODUTO',
                    'ncm' => '69139000', 'origin' => '0',
                    'quantity' => '1.000', 'unit_price' => '100.00',
                    'discount' => '10.00', 'line_total' => '90.00',
                    'tax_defaults' => [
                        'cfop_outbound_internal' => '5102', 'icms_csosn' => '103',
                        'pis_cst' => '01', 'pis_calc_type' => 'percentage', 'pis_rate' => '1.65',
                        'cofins_cst' => '01', 'cofins_calc_type' => 'percentage', 'cofins_rate' => '7.60',
                    ],
                ]],
                'payments' => [['payment_method'=>'dinheiro','payment_kind'=>'cash','amount'=>'90.00']],
                'change_amount'=>'0.00',
            ],
        ]);

        $ini = app(NFCeIniBuilder::class)->build($document, $company);
        self::assertStringContainsString('CSOSN=103', $ini);
        self::assertStringContainsString("[PIS001]\r\nCST=01\r\nvBC=90.00\r\npPIS=1.6500\r\nvPIS=1.49", $ini);
        self::assertStringContainsString("[COFINS001]\r\nCST=01\r\nvBC=90.00\r\npCOFINS=7.6000\r\nvCOFINS=6.84", $ini);
        self::assertStringContainsString("[Total]\r\nvBC=0.00", $ini);
        self::assertStringContainsString("vPIS=1.49\r\nvCOFINS=6.84\r\nvNF=90.00", $ini);
    }

    public function test_normal_crt_three_writes_icms_values_and_total_to_acbr_ini(): void
    {
        $company = new CompanySetting([
            'document'=>'39323356000100', 'legal_name'=>'EMPRESA EXEMPLO LTDA',
            'state_registration'=>'172036473', 'crt'=>'3', 'address'=>'RUA EXEMPLO',
            'address_number'=>'100', 'district'=>'CENTRO', 'city'=>'Rio do Antonio',
            'city_ibge_code'=>'2926806', 'state'=>'BA', 'zip_code'=>'46220000',
        ]);
        $job = new FiscalDocumentJob([
            'document_type'=>'nfce', 'environment'=>'homologation', 'series'=>1, 'document_number'=>21,
            'source_snapshot'=>[
                'total'=>'90.00',
                'items'=>[[
                    'item_type'=>'product', 'product_id'=>1, 'sku'=>'CST20', 'name'=>'PRODUTO',
                    'unit'=>'UN', 'ncm'=>'69139000', 'origin'=>'0', 'quantity'=>'1.000',
                    'unit_price'=>'100.00', 'discount'=>'10.00', 'line_total'=>'90.00',
                    'tax_defaults'=>[
                        'cfop_outbound_internal'=>'5102','icms_cst'=>'20',
                        'mod_bc'=>'3','icms_rate'=>'18','base_reduction_rate'=>'20',
                        'pis_cst'=>'49','cofins_cst'=>'49',
                    ],
                ]],
                'payments'=>[['payment_method'=>'cash','payment_kind'=>'cash','amount'=>'90.00']],
                'change_amount'=>'0.00',
            ],
        ]);
        $ini = app(NFCeIniBuilder::class)->build($job, $company);
        self::assertStringContainsString("[ICMS001]\r\norig=0\r\nCST=20\r\nmodBC=3\r\npRedBC=20.0000\r\nvBC=72.00\r\npICMS=18.0000\r\nvICMS=12.96", $ini);
        self::assertStringContainsString("[Total]\r\nvBC=72.00\r\nvICMS=12.96", $ini);
        self::assertStringContainsString("vNF=90.00", $ini);
    }

}
