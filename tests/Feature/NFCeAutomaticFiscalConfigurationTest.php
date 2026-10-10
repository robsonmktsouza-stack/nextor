<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\CompanySetting;
use App\Models\FiscalDocumentJob;
use App\Models\FiscalTaxGroup;
use App\Models\Product;
use App\Models\Sale;
use App\Services\FiscalPreparationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class NFCeAutomaticFiscalConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private function configureNfce(): void
    {
        AppSetting::put('fiscal','enabled',true);
        AppSetting::put('nfce','enabled',true);
        AppSetting::put('nfce','series',1);
        AppSetting::put('nfce','next_number',1);

        CompanySetting::query()->create([
            'state'=>'BA',
            'crt'=>'1',
        ]);
    }

    private function createPdvSale(?int $groupId = null, array $taxDefaults = []): Sale
    {
        $product = Product::query()->create([
            'sku'=>'TEST-AUTO-TAX',
            'name'=>'Produto de teste fiscal',
            'ncm'=>'69139000',
            'unit'=>'UN',
            'sale_price'=>'10.00',
            'stock_quantity'=>'8.000',
            'origin'=>'0',
            'tax_defaults'=>$taxDefaults,
            'fiscal_tax_group_id'=>$groupId,
        ]);
        $sale = Sale::query()->create([
            'source'=>'pdv',
            'operation_type'=>'sale',
            'status'=>'completed',
            'operation_date'=>'2026-10-08',
            'subtotal'=>'10.00',
            'discount_total'=>'0.00',
            'total'=>'10.00',
        ]);
        $sale->items()->create([
            'item_type'=>'product',
            'product_id'=>$product->id,
            'product_name'=>$product->name,
            'product_sku'=>$product->sku,
            'quantity'=>'1.000',
            'unit_price'=>'10.00',
            'discount'=>'0.00',
            'line_total'=>'10.00',
        ]);
        $sale->payments()->create([
            'installment'=>1,
            'amount'=>'10.00',
            'payment_method'=>'cash',
        ]);
        return $sale;
    }

    private function group(): FiscalTaxGroup
    {
        return FiscalTaxGroup::query()->create([
            'name'=>'Venda interna configurada',
            'kind'=>'products',
            'is_active'=>true,
            'is_default'=>false,
            'cfop_pattern'=>'x102',
            'icms_csosn'=>'101',
            'nfce_csosn'=>'102',
            'pis_cst'=>'49',
            'cofins_cst'=>'49',
            'tax_config'=>[],
        ]);
    }

    public function test_nfce_default_cfop_can_be_configured_in_the_system(): void
    {
        $admin=\App\Models\User::query()->create([
            'name'=>'Administrador','email'=>'nfce-settings@example.com',
            'password'=>'senhaSegura123','role'=>'admin','is_active'=>true,
        ]);
        $this->actingAs($admin)->post(route('settings.group.update','nfce'),[
            'environment'=>'homologation',
            'series'=>1,
            'next_number'=>5,
            'default_cfop'=>'5102',
        ])->assertSessionHasNoErrors();

        self::assertSame('5102',AppSetting::value('nfce','default_cfop'));
    }

    public function test_preparation_uses_assigned_group_without_manual_action_or_duplicate_number(): void
    {
        $this->configureNfce();
        $group=$this->group();
        $sale=$this->createPdvSale($group->id);
        $product=$sale->items->first()->product;
        $beforeStock=$product->stock_quantity;

        app(FiscalPreparationService::class)->prepareForSale($sale);

        $job=FiscalDocumentJob::query()
            ->where('sale_id',$sale->id)->where('document_type','nfce')->sole();
        $tax=$job->source_snapshot['items'][0]['tax_defaults'];

        self::assertSame('prepared',$job->status);
        self::assertSame($group->id,$tax['fiscal_group_id']);
        self::assertSame('5102',$tax['cfop_outbound_internal']);
        self::assertSame('102',$tax['icms_csosn']);
        self::assertSame('49',$tax['pis_cst']);
        self::assertSame('49',$tax['cofins_cst']);
        self::assertNull($job->error_message);
        self::assertSame($beforeStock,$product->fresh()->stock_quantity);

        app(FiscalPreparationService::class)->prepareForSale($sale);
        self::assertSame(1,FiscalDocumentJob::query()->where('sale_id',$sale->id)->count());
        self::assertSame($job->document_number,$job->fresh()->document_number);
    }

    public function test_missing_tax_configuration_creates_pending_document_without_guessing_codes(): void
    {
        $this->configureNfce();
        $sale=$this->createPdvSale();

        app(FiscalPreparationService::class)->prepareForSale($sale);

        $job=FiscalDocumentJob::query()->where('sale_id',$sale->id)->sole();
        $tax=$job->source_snapshot['items'][0]['tax_defaults'] ?? [];

        self::assertSame('prepared',$job->status);
        self::assertStringContainsString('Configuração fiscal:',(string)$job->error_message);
        self::assertArrayNotHasKey('fiscal_group_id',$tax);
        self::assertArrayNotHasKey('fiscal_rule_id',$tax);
        self::assertNull($job->access_key);
        self::assertNull($job->protocol);
    }

    public function test_legacy_product_codes_are_used_only_when_explicitly_complete(): void
    {
        $this->configureNfce();
        $sale=$this->createPdvSale(null,[
            'cfop_outbound_internal'=>'5102',
            'icms_csosn'=>'102',
            'pis_cst'=>'49',
            'cofins_cst'=>'49',
        ]);

        app(FiscalPreparationService::class)->prepareForSale($sale);

        $job=FiscalDocumentJob::query()->where('sale_id',$sale->id)->sole();
        $tax=$job->source_snapshot['items'][0]['tax_defaults'];

        self::assertSame('product',$tax['fiscal_config_source']);
        self::assertSame('5102',$tax['cfop']);
        self::assertNull($job->error_message);
    }
}
