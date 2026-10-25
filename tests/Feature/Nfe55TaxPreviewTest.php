<?php

namespace Tests\Feature;

use App\Models\CompanySetting;
use App\Models\NfeDraft;
use App\Models\NfeDraftItem;
use App\Models\Customer;
use App\Models\OperationNature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithConfiguredCompany;
use Tests\TestCase;

final class Nfe55TaxPreviewTest extends TestCase
{
    use RefreshDatabase,WithConfiguredCompany;

    private function init(): void
    {
        $this->actingAs(User::query()->create(['name'=>'Conferência fiscal',
            'email'=>'tax-preview@example.test','password'=>'password','role'=>'admin','is_active'=>true]));
        CompanySetting::current()->update(['crt'=>'3','state'=>'BA']);
    }

    private function data(): array
    {
        return ['operation_type'=>'outbound','destination'=>'internal','purpose'=>'normal',
            'issue_date'=>'2026-10-25','items_json'=>json_encode([[
                'quantity'=>'1','dimension_quantity'=>'1','unit_price'=>'100','origin'=>'0',
                'ncm'=>'61091000','cfop'=>'5102','tax_data'=>[
                    'icms_cst'=>'00','icms_rate'=>'18','pis_cst'=>'01','pis_rate'=>'1.65',
                    'cofins_cst'=>'01','cofins_rate'=>'7.6','ipi_cst'=>'50','ipi_rate'=>'10','ipi_enq'=>'999',
                    'ibs_cbs_cst'=>'000','ibs_cbs_class'=>'000001',
                    'ibs_uf_rate'=>'0.1','ibs_municipal_rate'=>'0','cbs_rate'=>'0.9',
                ],
            ]],JSON_THROW_ON_ERROR)];
    }

    public function test_preview_calculates_real_engines_without_creating_documents_or_items(): void
    {
        $this->init();
        $draftCount=NfeDraft::query()->count();$itemCount=NfeDraftItem::query()->count();
        $this->postJson(route('fiscal.nfe.preview-taxes'),$this->data())->assertOk()
            ->assertJsonPath('totals.total',110)
            ->assertJsonPath('totals.ipi',10)
            ->assertJsonPath('totals.icms',18)
            ->assertJsonPath('totals.pis',1.65)
            ->assertJsonPath('totals.tax_calculation_complete',true);
        self::assertSame($draftCount,NfeDraft::query()->count());
        self::assertSame($itemCount,NfeDraftItem::query()->count());
    }

    public function test_preview_marks_invalid_classification_pending_and_does_not_persist_it(): void
    {
        $this->init();$data=$this->data();$items=json_decode($data['items_json'],true);
        $items[0]['tax_data']['icms_cst']='61';
        $data['items_json']=json_encode($items,JSON_THROW_ON_ERROR);
        $this->postJson(route('fiscal.nfe.preview-taxes'),$data)->assertOk()
            ->assertJsonPath('totals.icms',null)
            ->assertJsonPath('totals.tax_calculation_complete',false);
        self::assertSame(0,NfeDraft::query()->count());
    }

    public function test_preview_rejects_non_list_item_json_and_more_than_eighty_items(): void
    {
        $this->init();$data=$this->data();
        $data['items_json']='{"product_name":"Objeto em vez de lista"}';
        $this->postJson(route('fiscal.nfe.preview-taxes'),$data)->assertUnprocessable();
        $data['items_json']=json_encode(array_fill(0,81,['quantity'=>1,'unit_price'=>1]),JSON_THROW_ON_ERROR);
        $this->postJson(route('fiscal.nfe.preview-taxes'),$data)->assertUnprocessable();
    }

    public function test_foreign_draft_preserves_structured_declarations_and_totals_import_tax_once(): void
    {
        $this->init();
        $customer=Customer::query()->create(['name'=>'Fornecedor estrangeiro','is_customer'=>true,
            'foreign_id'=>'FOREIGN-123','state'=>'EX','country_code'=>'2496','country_name'=>'Estados Unidos',
            'city'=>'New York','address'=>'Example Street','address_number'=>'1','district'=>'Central',
            'ie_indicator'=>'non_contributor']);
        $nature=OperationNature::query()->create(['name'=>'IMPORTAÇÃO DIRETA','operation_type'=>'inbound',
            'purpose'=>'normal','cfop_foreign'=>'3102','is_active'=>true]);
        $special=['import_taxes'=>['base'=>'100','rate'=>'10','customs_expenses'=>'5','iof_amount'=>'1'],
            'import_declarations'=>[['number'=>'2612345678','date'=>'2026-10-20',
                'clearance_location'=>'Porto de Salvador','clearance_uf'=>'BA','clearance_date'=>'2026-10-22',
                'transport_type'=>'1','afrmm_value'=>'0','intermediation_type'=>'1','exporter_code'=>'EXP-1',
                'additions'=>[['number'=>'1','sequence'=>'1','manufacturer_code'=>'FAB-1','discount'=>'0']]]]];
        $item=['product_name'=>'Mercadoria importada','quantity'=>'1','dimension_quantity'=>'1','unit_price'=>'100',
            'unit'=>'UN','origin'=>'1','ncm'=>'61091000','other_expenses'=>'6','cfop'=>'',
            'tax_data'=>['icms_cst'=>'00','icms_rate'=>'0','icms_base'=>'100',
                'pis_cst'=>'49','pis_rate'=>'0','cofins_cst'=>'49','cofins_rate'=>'0',
                'ipi_cst'=>'00','ipi_rate'=>'0','ipi_enq'=>'999',
                'ibs_cbs_cst'=>'000','ibs_cbs_class'=>'000001','ibs_cbs_base'=>'116','ibs_uf_rate'=>'0','ibs_municipal_rate'=>'0','cbs_rate'=>'0'],
            'special_data'=>$special];
        $data=['draft_action'=>'draft','operation_nature_id'=>$nature->id,'customer_id'=>$customer->id,
            'operation_type'=>'inbound','destination'=>'foreign','fiscal_operation'=>'import','purpose'=>'normal',
            'presence'=>'not_applicable','issue_date'=>'2026-10-25','issue_time'=>'12:00',
            'freight_mode'=>'9','payment_type'=>'01','payment_condition'=>'a_vista',
            'items_json'=>json_encode([$item],JSON_THROW_ON_ERROR),
            'duplicates_json'=>'[]','payments_json'=>'[]','references_json'=>'[]',
            'tax_authority_info'=>'Conferência da declaração de importação.'];
        $this->post(route('fiscal.nfe.store'),$data)->assertSessionHasNoErrors();
        $draft=NfeDraft::query()->firstOrFail();$stored=$draft->items()->firstOrFail();
        self::assertSame('import',data_get($draft->custom_fields,'fiscal_operation'));
        self::assertSame('3102',$stored->cfop);
        self::assertSame($special,$stored->special_data);
        self::assertEquals(10,$draft->totals['ii']);
        self::assertEquals(116,$draft->totals['total']);
        self::assertNull($draft->document_number);
        self::assertNull($draft->validated_at);
        $this->get(route('fiscal.nfe.edit',$draft))->assertOk()
            ->assertSee('nfeItemForeignTrade')->assertSee('Conferência da declaração de importação.');
    }
}
