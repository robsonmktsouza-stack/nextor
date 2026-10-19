<?php

namespace Tests\Feature;

use App\Models\CompanySetting;
use App\Models\FiscalDocumentJob;
use App\Services\Fiscal\NFCeIniBuilder;
use Tests\TestCase;

/**
 * Snapshots NFC-e para o Simples Nacional, inclusive mercadorias com
 * ICMS-ST anterior e PIS/COFINS de revenda monofásica (CST 04).
 */
final class NFCeSimplesProductionTest extends TestCase
{
    private function company(string $crt = '1'): CompanySetting
    {
        return new CompanySetting([
            'document' => '39323356000100',
            'legal_name' => 'EMPRESA SIMPLES TESTE LTDA',
            'crt' => $crt,
            'state_registration' => '172036473',
            'address' => 'RUA DE TESTE',
            'address_number' => '100',
            'district' => 'CENTRO',
            'city' => 'SALVADOR',
            'city_ibge_code' => '2927408',
            'state' => 'BA',
            'zip_code' => '40000000',
        ]);
    }

    private function item(int $id, string $cfop, string $csosn, string $pis, string $cofins, array $extra = []): array
    {
        return [
            'item_type' => 'product',
            'product_id' => $id,
            'name' => 'MERCADORIA DE TESTE '.$id,
            'sku' => 'SKU-'.$id,
            'ncm' => '87089990',
            'origin' => '0',
            'unit' => 'UN',
            'quantity' => $id===2 ? '2.000' : '1.000',
            'unit_price' => $id===2 ? '15.00' : '10.00',
            'discount' => '0.00',
            'line_total' => $id===2 ? '30.00' : '10.00',
            'tax_defaults' => array_replace([
                'cfop_outbound_internal' => $cfop,
                'icms_csosn' => $csosn,
                'pis_cst' => $pis,
                'cofins_cst' => $cofins,
            ], $extra),
        ];
    }

    private function job(array $items, string $total): FiscalDocumentJob
    {
        return new FiscalDocumentJob([
            'document_type' => 'nfce',
            'environment' => 'production',
            'emission_mode' => 'normal',
            'series' => 1,
            'document_number' => 1234,
            'source_snapshot' => [
                'items' => $items,
                'total' => $total,
                'payments' => [['payment_kind'=>'cash','payment_method'=>'cash','amount'=>$total]],
                'change_amount' => '0.00',
            ],
        ]);
    }

    public function test_simple_sale_with_csosn_102_and_st_500_generates_consistent_acbr_ini(): void
    {
        $items = [
            $this->item(1, '5102', '102', '49', '49'),
            $this->item(2, '5405', '500', '04', '04', [
                'st_retained_amount_scope'=>'unit',
                'icms_st_retained_base'=>'8.00',
                'icms_st_retained_value'=>'1.44',
                'st_retained_rate'=>'18.00',
            ]),
        ];
        $ini = app(NFCeIniBuilder::class)->build($this->job($items,'40.00'),$this->company());

        self::assertStringContainsString("tpAmb=1",$ini);
        self::assertStringContainsString("CRT=1",$ini);
        self::assertStringContainsString("CSOSN=102",$ini);
        self::assertStringContainsString("CSOSN=500",$ini);
        self::assertStringContainsString("CFOP=5405",$ini);
        self::assertStringContainsString("vBCSTRet=16.00",$ini);
        self::assertStringContainsString("vICMSSTRet=2.88",$ini);
        self::assertStringContainsString("vPIS=0.00",$ini);
        self::assertStringContainsString("vCOFINS=0.00",$ini);
        self::assertStringContainsString("vNF=40.00",$ini);
        self::assertStringNotContainsString("[IBSCBS001]",$ini);
        self::assertStringNotContainsString("NOTA FISCAL EMITIDA EM AMBIENTE DE HOMOLOGACAO",$ini);
        self::assertStringContainsString("[PIS002]\r\nCST=04",$ini);
        self::assertStringContainsString("[COFINS002]\r\nCST=04",$ini);
    }

    public function test_simples_2026_does_not_invent_ibs_cbs_for_ordinary_sales(): void
    {
        $ini = app(NFCeIniBuilder::class)->build(
            $this->job([$this->item(1,'5102','400','06','06')], '10.00'),
            $this->company()
        );
        self::assertStringContainsString('CSOSN=400',$ini);
        self::assertStringContainsString('[PIS001]'."\r\n".'CST=06',$ini);
        self::assertStringNotContainsString('[IBSCBSTot]',$ini);
        self::assertStringContainsString('vNF=10.00',$ini);
    }
}
