<?php

namespace Tests\Feature;

use App\Models\CompanySetting;
use App\Models\FiscalDocumentJob;
use App\Models\PaymentMethod;
use App\Services\Fiscal\NFCeIniBuilder;
use RuntimeException;
use Tests\TestCase;

final class NFCePaymentIniRegressionTest extends TestCase
{
    private function company(): CompanySetting
    {
        return new CompanySetting([
            'document'=>'39323356000100',
            'legal_name'=>'EMPRESA EXEMPLO LTDA',
            'state_registration'=>'172036473',
            'crt'=>'1','address'=>'RUA TESTE',
            'address_number'=>'100','district'=>'CENTRO','city'=>'Salvador',
            'city_ibge_code'=>'2927408','state'=>'BA','zip_code'=>'40000000',
        ]);
    }

    private function document(array $payments, string $total='20.00', string $change='0.00'): FiscalDocumentJob
    {
        return new FiscalDocumentJob([
            'document_type'=>'nfce','environment'=>'homologation',
            'series'=>1,'document_number'=>100,
            'source_snapshot'=>[
                'total'=>$total,
                'change_amount'=>$change,
                'items'=>[[
                    'item_type'=>'product','product_id'=>1,
                    'sku'=>'PROD-1','name'=>'PRODUTO DE TESTE',
                    'unit'=>'UN','ncm'=>'22021000','origin'=>'0',
                    'quantity'=>'1.000','unit_price'=>$total,
                    'line_total'=>$total,'discount'=>'0.00',
                    'tax_defaults'=>[
                        'cfop_outbound_internal'=>'5102',
                        'icms_csosn'=>'102','pis_cst'=>'49','cofins_cst'=>'49',
                    ],
                ]],
                'payments'=>$payments,
            ],
        ]);
    }

    public function test_fiscal_codes_and_metadata_are_distinct_for_credit_debit_pix_and_cash(): void
    {
        $card=['integration_type'=>'1','card_brand'=>'01',
            'institution_document'=>'11222333000181',
            'authorization_code'=>'AUTH-123',
            'transaction_document'=>'11222333000181','transaction_state'=>'BA',
            'beneficiary_document'=>'11222333000181','terminal_id'=>'T-1'];
        $payments=[
            array_merge(['payment_method'=>'credit_card','payment_kind'=>'credit_card','amount'=>'4.00'],$card),
            array_merge(['payment_method'=>'debit_card','payment_kind'=>'debit_card','amount'=>'5.00'],$card),
            ['payment_method'=>'pix','payment_kind'=>'pix','amount'=>'6.00'],
            ['payment_method'=>'cash','payment_kind'=>'cash','amount'=>'5.00'],
        ];
        $ini=app(NFCeIniBuilder::class)->build($this->document($payments),$this->company());
        self::assertStringContainsString("[pag001]\r\ntPag=03\r\nvPag=4.00", $ini);
        self::assertStringContainsString("[pag002]\r\ntPag=04\r\nvPag=5.00", $ini);
        self::assertStringContainsString("[pag003]\r\ntPag=17\r\nvPag=6.00", $ini);
        self::assertStringContainsString("[pag004]\r\ntPag=01\r\nvPag=5.00", $ini);
        self::assertSame(2,substr_count($ini,'tpIntegra=1'));
        self::assertSame(2,substr_count($ini,'tBand=01'));
        self::assertSame(2,substr_count($ini,'cAut=AUTH-123'));
        self::assertSame(2,substr_count($ini,'CNPJ=11222333000181'));
        self::assertSame(2,substr_count($ini,'CNPJPag=11222333000181'));
        self::assertSame(2,substr_count($ini,'UFPag=BA'));
        self::assertSame(2,substr_count($ini,'CNPJReceb=11222333000181'));
        self::assertSame(2,substr_count($ini,'idTermPag=T-1'));
    }

    public function test_original_generic_card_code_is_mapped_but_ambiguous_legacy_card_is_refused(): void
    {
        self::assertContains('27',\App\Support\NFCeCardBrands::codes());
        self::assertContains('99',\App\Support\NFCeCardBrands::codes());
        self::assertSame('credit_card',PaymentMethod::resolveFiscalKind('card','credit_card'));
        self::assertSame('debit_card',PaymentMethod::resolveFiscalKind('card','debit_card'));
        self::assertSame('card',PaymentMethod::resolveFiscalKind('card','my-card'));

        $legacy=[['payment_method'=>'debit_card','payment_kind'=>'card',
            'amount'=>'20.00','integration_type'=>'2']];
        $ini=app(NFCeIniBuilder::class)->build($this->document($legacy),$this->company());
        self::assertStringContainsString("tPag=04\r\nvPag=20.00", $ini);
        self::assertStringContainsString('tpIntegra=2',$ini);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sem classificação fiscal');
        app(NFCeIniBuilder::class)->build($this->document([[
            'payment_method'=>'my-card','payment_kind'=>'card',
            'amount'=>'20.00','integration_type'=>'2',
        ]]),$this->company());
    }

    public function test_mismatched_payment_total_fails_before_ini_is_returned(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Pagamentos não conciliam');
        app(NFCeIniBuilder::class)->build($this->document([
            ['payment_method'=>'cash','payment_kind'=>'cash','amount'=>'19.99'],
        ]),$this->company());
    }

    public function test_mixed_cash_and_pix_place_change_on_last_payment_without_changing_sale_total(): void
    {
        $ini=app(NFCeIniBuilder::class)->build($this->document([
            ['payment_method'=>'cash','payment_kind'=>'cash','amount'=>'12.00'],
            ['payment_method'=>'pix','payment_kind'=>'pix','amount'=>'8.00'],
        ],'20.00','3.00'),$this->company());
        self::assertStringContainsString("[pag001]\r\ntPag=01\r\nvPag=15.00", $ini);
        self::assertStringContainsString("[pag002]\r\ntPag=17\r\nvPag=8.00\r\nvTroco=3.00", $ini);
        self::assertStringContainsString("vNF=20.00", $ini);
    }
}
