<?php

namespace Tests\Feature;

use App\Models\CompanySetting;
use App\Models\FiscalDocumentJob;
use App\Services\Fiscal\NFCeIniBuilder;
use Tests\TestCase;

final class NFCeRtcIniTest extends TestCase
{
    private function company(): CompanySetting
    {
        return new CompanySetting([
            'document'=>'11222333000181',
            'legal_name'=>'EMITENTE TESTE',
            'state_registration'=>'123456789',
            'crt'=>'3',
            'address'=>'RUA EXEMPLO',
            'address_number'=>'10',
            'district'=>'CENTRO',
            'city'=>'Salvador',
            'city_ibge_code'=>'2927408',
            'state'=>'BA',
            'zip_code'=>'40000000',
            'timezone'=>'America/Bahia',
        ]);
    }

    private function item(int $i): array
    {
        return [
            'item_type'=>'product',
            'product_id'=>$i,
            'name'=>'MERCADORIA TESTE',
            'sku'=>'TESTE-'.$i,
            'ncm'=>'22021000',
            'origin'=>'0',
            'unit'=>'UN',
            'quantity'=>'1.000',
            'unit_price'=>'100.00',
            'discount'=>'0.00',
            'line_total'=>'100.00',
            'tax_defaults'=>[
                'cfop_outbound_internal'=>'5102',
                'icms_cst'=>'00',
                'mod_bc'=>'3',
                'icms_rate'=>'18.00',
                'pis_cst'=>'49',
                'cofins_cst'=>'49',
                'ibs_cbs_cst'=>'000',
                'ibs_cbs_class'=>'000001',
                'ibs_uf_rate'=>'0.1000',
                'ibs_municipal_rate'=>'0.0000',
                'cbs_rate'=>'0.9000',
            ],
        ];
    }

    private function job(array $items): FiscalDocumentJob
    {
        return new FiscalDocumentJob([
            'document_type'=>'nfce',
            'environment'=>'homologation',
            'emission_mode'=>'normal',
            'series'=>1,
            'document_number'=>15,
            'source_snapshot'=>[
                'operation_date'=>'2026-10-19',
                'total'=>number_format(count($items)*100,2,'.',''),
                'items'=>$items,
                'payments'=>[[
                    'payment_method'=>'cash',
                    'payment_kind'=>'cash',
                    'amount'=>number_format(count($items)*100,2,'.',''),
                ]],
                'change_amount'=>'0.00',
            ],
        ]);
    }

    public function test_two_items_generate_exact_acbr_rtc_groups_and_document_totals(): void
    {
        $ini=app(NFCeIniBuilder::class)->build(
            $this->job([$this->item(1),$this->item(2)]),
            $this->company()
        );

        foreach (['[IBSCBS001]','[gIBSCBS001]','[gIBSUF001]',
            '[gIBSMun001]','[gCBS001]','[IBSCBS002]',
            '[gIBSCBS002]','[IBSCBSTot]','[gIBS]',
            '[gIBSUFTot]','[gIBSMunTot]','[gCBSTot]'] as $section) {
            self::assertStringContainsString($section,$ini);
        }
        self::assertStringContainsString("cClassTrib=000001",$ini);
        self::assertStringContainsString("vBCIBSCBS=200.00",$ini);
        self::assertStringContainsString("vIBSUF=0.20",$ini);
        self::assertStringContainsString("vIBSMun=0.00",$ini);
        self::assertStringContainsString("vCBS=1.80",$ini);
        self::assertStringContainsString("vIBS=0.20",$ini);
        self::assertStringContainsString("vNF=200.00",$ini);
        self::assertStringNotContainsString("[IS001]",$ini);
    }

    public function test_incomplete_rtc_classification_cannot_build_acbr_ini(): void
    {
        $item=$this->item(1);
        unset($item['tax_defaults']['cbs_rate']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cbs_rate');
        app(NFCeIniBuilder::class)->build($this->job([$item]),$this->company());
    }
}
