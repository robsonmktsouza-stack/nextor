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
        self::assertStringContainsString("vBCIBSCBS=164.00",$ini);
        self::assertStringContainsString("vIBSUF=0.16",$ini);
        self::assertStringContainsString("vIBSMun=0.00",$ini);
        self::assertStringContainsString("vCBS=1.48",$ini);
        self::assertStringContainsString("vIBS=0.16",$ini);
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
    public function test_normal_icms_51_includes_legal_basis_and_deducts_only_tax_due_from_rtc(): void
    {
        $item=$this->item(1);
        $item['tax_defaults']=array_replace($item['tax_defaults'],[
            'icms_cst'=>'51',
            'icms_deferral_rate'=>'50.0000',
            'icms_deferral_legal_basis'=>'Artigo aplicável informado pelo escritório',
        ]);
        $ini=app(NFCeIniBuilder::class)->build($this->job([$item]),$this->company());
        self::assertStringContainsString('pDif=50.0000',$ini);
        self::assertStringContainsString('vICMSOp=18.00',$ini);
        self::assertStringContainsString('vICMSDif=9.00',$ini);
        self::assertStringContainsString('vICMS=9.00',$ini);
        self::assertStringContainsString('infAdProd=ICMS diferido conforme Artigo aplicável informado pelo escritório',$ini);
        self::assertStringContainsString('vBCIBSCBS=91.00',$ini);
    }

    public function test_normal_icms_60_writes_previous_st_and_rtc_without_double_counting(): void
    {
        $item=$this->item(1);
        $item['tax_defaults']=array_replace($item['tax_defaults'],[
            'cfop_outbound_internal'=>'5405',
            'icms_cst'=>'60',
            'st_retained_amount_scope'=>'line',
            'icms_st_retained_base'=>'120.00',
            'icms_st_retained_value'=>'21.60',
            'st_retained_rate'=>'18.0000',
            'icms_substitutor_value'=>'12.00',
        ]);
        $ini=app(NFCeIniBuilder::class)->build($this->job([$item]),$this->company());
        self::assertStringContainsString("CST=60\r\nvBCSTRet=120.00",$ini);
        self::assertStringContainsString('pST=18.0000',$ini);
        self::assertStringContainsString('vICMSSTRet=21.60',$ini);
        self::assertStringContainsString('vICMSSubstituto=12.00',$ini);
        self::assertStringContainsString('vBCIBSCBS=100.00',$ini);
        self::assertStringContainsString('vNF=100.00',$ini);
    }

    public function test_zero_rate_food_class_emits_acbr_reductions_without_deleting_base(): void
    {
        $item=$this->item(1);
        $item['tax_defaults']=array_replace($item['tax_defaults'],[
            'ibs_cbs_cst'=>'200','ibs_cbs_class'=>'200003',
            'ibs_uf_reduction_rate'=>'100','ibs_municipal_reduction_rate'=>'100',
            'cbs_reduction_rate'=>'100',
        ]);
        $ini=app(NFCeIniBuilder::class)->build($this->job([$item]),$this->company());
        self::assertStringContainsString("[IBSCBS001]\r\nCST=200\r\ncClassTrib=200003",$ini);
        self::assertSame(3,substr_count($ini,'pRedAliq=100.0000'));
        self::assertSame(3,substr_count($ini,'pAliqEfet=0.0000'));
        self::assertStringContainsString('vBCIBSCBS=82.00',$ini);
        self::assertStringContainsString('vCBS=0.00',$ini);
        self::assertStringContainsString('vIBS=0.00',$ini);
        self::assertStringContainsString('vNF=100.00',$ini);
    }

}
