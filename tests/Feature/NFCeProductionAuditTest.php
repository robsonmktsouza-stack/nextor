<?php

namespace Tests\Feature;

use App\Models\CompanySetting;
use App\Models\FiscalDocumentJob;
use App\Models\Product;
use App\Models\Sale;
use App\Services\Fiscal\NFCeCatalogAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class NFCeProductionAuditTest extends TestCase
{
    use RefreshDatabase;

    private function company(): void
    {
        CompanySetting::current()->update([
            'document'=>'39323356000100',
            'state'=>'BA',
            'crt'=>'1',
            'city_ibge_code'=>'2927408',
        ]);
    }

    private function product(string $sku, string $ncm, array $tax): Product
    {
        return Product::query()->create([
            'sku'=>$sku,
            'name'=>'Mercadoria '.$sku,
            'is_active'=>true,
            'usage_type'=>'product',
            'unit'=>'UN',
            'origin'=>'0',
            'ncm'=>$ncm,
            'sale_price'=>'25.00',
            'stock_quantity'=>'3.000',
            'tax_defaults'=>$tax,
        ]);
    }

    public function test_auditor_checks_simplified_tax_rules_without_creating_a_sale_or_invoice(): void
    {
        $this->company();
        $good=$this->product('OK','87089990',[
            'cfop_outbound_internal'=>'5102',
            'icms_csosn'=>'102',
            'pis_cst'=>'49',
            'cofins_cst'=>'49',
        ]);
        $bad=$this->product('SEM-NCM','',[
            'cfop_outbound_internal'=>'5102',
            'icms_csosn'=>'102',
            'pis_cst'=>'49',
            'cofins_cst'=>'49',
        ]);

        $result=app(NFCeCatalogAuditService::class)->run();
        self::assertSame(2,$result['checked']);
        self::assertSame(1,$result['invalid']);
        self::assertStringContainsString('NCM',implode(' ', $result['examples']));
        self::assertSame('3.000',(string)$good->fresh()->stock_quantity);
        self::assertSame('3.000',(string)$bad->fresh()->stock_quantity);
        self::assertSame(0,Sale::query()->count());
        self::assertSame(0,FiscalDocumentJob::query()->count());
    }

    public function test_production_audit_is_read_only_and_does_not_display_csc(): void
    {
        $this->company();
        \App\Models\AppSetting::put('nfce','production_csc_token','SECRET-NUNCA-EXIBIR',true);
        $this->artisan('acbr:nfce-check', [
            '--environment'=>'production',
            '--catalog'=>true,
        ])
            ->expectsOutputToContain('Auditoria NFC-e: produção')
            ->expectsOutputToContain('Catálogo: 0 mercadorias verificadas, 0 com pendências fiscais.')
            ->expectsOutputToContain('Nenhuma nota, número, certificado ou configuração foi modificado.')
            ->assertExitCode(1);

        self::assertSame('SECRET-NUNCA-EXIBIR',
            \App\Models\AppSetting::value('nfce','production_csc_token'));
        self::assertSame(0,FiscalDocumentJob::query()->count());
    }
}
