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
                    'ncm' => '22021000',
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
        self::assertStringContainsString('mod=65', $ini);
        self::assertStringContainsString('tpAmb=2', $ini);
        self::assertStringContainsString('[Produto001]', $ini);
        self::assertStringContainsString('CFOP=5102', $ini);
        self::assertStringContainsString('CSOSN=102', $ini);
        self::assertStringContainsString('[pag001]', $ini);
        self::assertStringContainsString('tPag=01', $ini);
        self::assertStringContainsString('vNF=10.00', $ini);
        self::assertStringNotContainsString('Senha', $ini);
        self::assertStringNotContainsString('CSC', $ini);
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
    }
}
