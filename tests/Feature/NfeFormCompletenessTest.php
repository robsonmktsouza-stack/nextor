<?php

namespace Tests\Feature;

use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\NfeDraft;
use App\Models\OperationNature;
use App\Models\User;
use App\Services\Fiscal\Nfe55IniBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithConfiguredCompany;
use Tests\TestCase;
use RuntimeException;

final class NfeFormCompletenessTest extends TestCase
{
    use RefreshDatabase,WithConfiguredCompany;

    private function init(): array
    {
        $user=User::query()->create([
            'name'=>'Teste NF-e completa','email'=>'form55@example.test',
            'password'=>'password','role'=>'admin','is_active'=>true,
        ]);
        $company=CompanySetting::current();
        $company->update([
            'document'=>'12345678000100','legal_name'=>'Emitente NF-e Completa',
            'state_registration'=>'12345678','state'=>'BA','city'=>'Salvador',
            'city_ibge_code'=>'2927408','address'=>'Rua da Empresa',
            'address_number'=>'101','district'=>'Centro','zip_code'=>'40000000','crt'=>'1',
        ]);
        $nature=OperationNature::query()->create([
            'name'=>'VENDA DE MERCADORIAS','operation_type'=>'outbound','purpose'=>'normal',
            'cfop_internal'=>'5102','is_active'=>true,
        ]);
        $customer=Customer::query()->create([
            'name'=>'Cliente Exemplo','document'=>'12345678000190',
            'is_customer'=>true,'state'=>'BA','city'=>'Salvador',
            'city_ibge_code'=>'2927408','zip_code'=>'40001000',
            'address'=>'Rua do Cliente','address_number'=>'44','district'=>'Centro',
            'ie_indicator'=>'non_contributor',
        ]);
        $this->actingAs($user);
        return [$company,$nature,$customer];
    }

    private function data(OperationNature $nature,Customer $customer): array
    {
        return [
            'operation_nature_id'=>$nature->id,'customer_id'=>$customer->id,
            'operation_type'=>'outbound','destination'=>'internal','purpose'=>'normal',
            'presence'=>'presential','issue_date'=>'2026-10-23','issue_time'=>'12:00',
            'freight_mode'=>'9','freight_value'=>'0',
            'discount'=>'0','surcharge'=>'0','payment_type'=>'01',
            'payment_condition'=>'a_vista',
            'items_json'=>json_encode([[
                'product_name'=>'Camiseta comercial','product_sku'=>'CAM-01',
                'quantity'=>2,'dimension_quantity'=>1,'unit_price'=>100,
                'freight'=>10,'insurance'=>3,'other_expenses'=>2,'discount'=>5,
                'cfop'=>'5102','origin'=>'0','unit'=>'UN','tax_unit'=>'UN',
                'ncm'=>'61091000','tax_data'=>[
                    'icms_csosn'=>'102','pis_cst'=>'49','cofins_cst'=>'49','ipi_cst'=>'53',
                ],
            ]],JSON_THROW_ON_ERROR),
            'payments_json'=>json_encode([[
                'type'=>'17','amount'=>30,'condition'=>'a_vista',
            ]],JSON_THROW_ON_ERROR),
            'duplicates_json'=>'[]','references_json'=>'[]',
            'different_delivery'=>'1','delivery_name'=>'Responsável de Entrega',
            'delivery_document'=>'','delivery_zip_code'=>'40012000',
            'delivery_address'=>'Rua Entrega','delivery_address_number'=>'42',
            'delivery_address_complement'=>'Sala 2','delivery_district'=>'Comércio',
            'delivery_city'=>'Salvador','delivery_state'=>'BA',
            'delivery_city_ibge_code'=>'2927408',
        ];
    }

    public function test_delivery_charges_payments_and_invoice_totals_survive_save_and_reopen(): void
    {
        [$company,$nature,$customer]=$this->init();
        $response=$this->post(route('fiscal.nfe.store'),$this->data($nature,$customer));
        $draft=NfeDraft::query()->firstOrFail();
        $response->assertRedirect(route('fiscal.nfe.edit',$draft))->assertSessionHasNoErrors();
        self::assertTrue($draft->different_delivery);
        self::assertSame('Rua Entrega',data_get($draft->delivery_snapshot,'address'));
        self::assertSame('2927408',data_get($draft->delivery_snapshot,'city_ibge_code'));
        self::assertSame('210.00',$draft->totals['total']==210?'210.00':'unexpected');
        self::assertEquals(210.0,(float)data_get($draft->invoice_data,'net_value'));
        self::assertEquals(215.0,(float)data_get($draft->invoice_data,'original_value'));
        self::assertEquals(180.0,(float)data_get($draft->payments,'0.amount'));
        self::assertEquals(30.0,(float)data_get($draft->payments,'1.amount'));
        self::assertEquals(10.0,(float)$draft->items->first()->freight);
        self::assertEquals(3.0,(float)$draft->items->first()->insurance);
        self::assertEquals(2.0,(float)$draft->items->first()->other_expenses);
        self::assertEquals(5.0,(float)$draft->items->first()->discount);
        self::assertEquals(210.0,(float)$draft->items->first()->line_total);
        $this->get(route('fiscal.nfe.edit',$draft))->assertOk()
            ->assertSee('name="different_delivery"',false)
            ->assertSee('Rua Entrega')->assertSee('nfeExtraPaymentsBody')
            ->assertSee('nfeItemInsurance')->assertSee('nfeIbsUfRate');
    }

    public function test_acbr_ini_contains_delivery_charges_and_two_payments(): void
    {
        [$company,$nature,$customer]=$this->init();
        $this->post(route('fiscal.nfe.store'),$this->data($nature,$customer))
            ->assertSessionHasNoErrors();
        $draft=NfeDraft::query()->firstOrFail();
        $ini=app(Nfe55IniBuilder::class)->build($draft,$company,42,12345678);
        foreach([
            '[Entrega]','xLgr=Rua Entrega','CEP=40012000',
            'vFrete=10.00','vSeg=3.00','vOutro=2.00','vDesc=5.00',
            'vProd=200.00','vNF=210.00','[pag001]','[pag002]','vPag=180.00',
            'vPag=30.00','tPag=17',
        ] as $part)self::assertStringContainsString($part,$ini);
    }

    public function test_invalid_alternative_address_or_payment_never_creates_draft(): void
    {
        [, $nature,$customer]=$this->init();
        $data=$this->data($nature,$customer);
        unset($data['delivery_city_ibge_code']);
        $this->post(route('fiscal.nfe.store'),$data)
            ->assertSessionHasErrors('delivery_city_ibge_code');
        self::assertSame(0,NfeDraft::query()->count());

        $data=$this->data($nature,$customer);
        $data['payments_json']=json_encode([['type'=>'17','amount'=>300,'condition'=>'a_vista']]);
        $this->post(route('fiscal.nfe.store'),$data)
            ->assertSessionHasErrors('payments_json');
        self::assertSame(0,NfeDraft::query()->count());

        $data=$this->data($nature,$customer);
        $items=json_decode($data['items_json'],true);
        $items[0]['discount']=900;
        $data['items_json']=json_encode($items);
        $data['payments_json']='[]';
        $data['payment_type']='90';
        $this->post(route('fiscal.nfe.store'),$data)
            ->assertSessionHasErrors('items_json');
        self::assertSame(0,NfeDraft::query()->count());
    }

    public function test_unrecognized_rtc_editor_fields_must_not_disappear_from_fiscal_xml(): void
    {
        [$company,$nature,$customer]=$this->init();
        $data=$this->data($nature,$customer);
        $items=json_decode($data['items_json'],true);
        $items[0]['tax_data']['ibs_cst']='000';
        $items[0]['tax_data']['cbs_cst']='000';
        $items[0]['tax_data']['tax_classification_code']='000001';
        $data['items_json']=json_encode($items);
        $this->post(route('fiscal.nfe.store'),$data)->assertSessionHasNoErrors();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('RTC:');
        app(Nfe55IniBuilder::class)->build(NfeDraft::query()->firstOrFail(),$company,42,12345678);
    }

    public function test_document_level_charges_are_distributed_to_items_without_losing_cents(): void
    {
        [$company,$nature,$customer]=$this->init();
        $data=$this->data($nature,$customer);
        $data['freight_mode']='0';
        $data['freight_value']='0.03';
        $data['surcharge']='0.03';
        $data['discount']='0.03';
        $items=json_decode($data['items_json'],true,512,JSON_THROW_ON_ERROR);
        $items[]=[
            'product_name'=>'Segunda mercadoria','product_sku'=>'ITEM-02',
            'quantity'=>1,'dimension_quantity'=>1,'unit_price'=>100,
            'cfop'=>'5102','origin'=>'0','unit'=>'UN','tax_unit'=>'UN','ncm'=>'61091000',
            'tax_data'=>[
                'icms_csosn'=>'102','pis_cst'=>'49','cofins_cst'=>'49','ipi_cst'=>'53',
            ],
        ];
        $data['items_json']=json_encode($items,JSON_THROW_ON_ERROR);

        $this->post(route('fiscal.nfe.store'),$data)->assertSessionHasNoErrors();
        $draft=NfeDraft::query()->firstOrFail()->load('items');
        self::assertEqualsWithDelta(310.03,(float)$draft->totals['total'],0.0001);
        // Allocation is exclusive to the fiscal output; editing the draft retains the entered amounts.
        self::assertEqualsWithDelta(10,(float)$draft->items[0]->freight,0.0001);
        self::assertEqualsWithDelta(0,(float)$draft->items[1]->freight,0.0001);
        $ini=app(Nfe55IniBuilder::class)->build($draft,$company,42,12345678);

        $first=explode('[ICMS001]',explode('[Produto001]',$ini,2)[1],2)[0];
        foreach(['vProd=200.00','vFrete=10.02','vSeg=3.00',
            'vOutro=2.02','vDesc=5.02'] as $expected){
            self::assertStringContainsString($expected,$first);
        }
        $second=explode('[ICMS002]',explode('[Produto002]',$ini,2)[1],2)[0];
        foreach(['vProd=100.00','vFrete=0.01','vOutro=0.01',
            'vDesc=0.01'] as $expected){
            self::assertStringContainsString($expected,$second);
        }
        $total=explode('[Transportador]',explode('[Total]',$ini,2)[1],2)[0];
        foreach(['vProd=300.00','vFrete=10.03','vSeg=3.00',
            'vOutro=2.03','vDesc=5.03','vNF=310.03'] as $expected){
            self::assertStringContainsString($expected,$total);
        }
    }

    public function test_discount_larger_than_invoice_is_rejected_before_creating_draft(): void
    {
        [, $nature,$customer]=$this->init();
        $data=$this->data($nature,$customer);
        $data['discount']='300.00';
        $this->post(route('fiscal.nfe.store'),$data)->assertSessionHasErrors('discount');
        self::assertSame(0,NfeDraft::query()->count());
    }

    public function test_invoice_installments_are_checked_before_saving_and_mapped_into_ini(): void
    {
        [$company,$nature,$customer]=$this->init();
        $data=$this->data($nature,$customer);
        $data['duplicates_json']=json_encode([[
            'number'=>'001','due_date'=>'2026-02-31','value'=>210,
        ]],JSON_THROW_ON_ERROR);
        $this->post(route('fiscal.nfe.store'),$data)->assertSessionHasErrors('duplicates_json');
        self::assertSame(0,NfeDraft::query()->count());

        $data['duplicates_json']=json_encode([[
            'number'=>'001','due_date'=>'2026-12-05','value'=>209.99,
        ]],JSON_THROW_ON_ERROR);
        $this->post(route('fiscal.nfe.store'),$data)->assertSessionHasErrors('duplicates_json');
        self::assertSame(0,NfeDraft::query()->count());

        $data['duplicates_json']=json_encode([[
            'number'=>'001','due_date'=>'2026-12-05','value'=>210,
        ]],JSON_THROW_ON_ERROR);
        $this->post(route('fiscal.nfe.store'),$data)->assertSessionHasErrors('duplicates_json');
        self::assertSame(0,NfeDraft::query()->count());

        $data['payment_type']='14';
        $data['payments_json']='[]';
        $this->post(route('fiscal.nfe.store'),$data)->assertSessionHasNoErrors();
        $draft=NfeDraft::query()->firstOrFail();
        self::assertSame('001',$draft->duplicates[0]['number']);
        $ini=app(Nfe55IniBuilder::class)->build($draft,$company,42,12345678);
        foreach(['[Fatura]','vLiq=210.00','[Duplicata001]',
            'nDup=001','dVenc=05/12/2026','vDup=210.00'] as $expected){
            self::assertStringContainsString($expected,$ini);
        }
    }

    public function test_mercantile_payment_requires_invoices_with_sequential_due_dates(): void
    {
        [, $nature,$customer]=$this->init();
        $data=$this->data($nature,$customer);
        $data['payment_type']='14';
        $data['payments_json']='[]';
        $this->post(route('fiscal.nfe.store'),$data)->assertSessionHasErrors('duplicates_json');

        $data['duplicates_json']=json_encode([
            ['number'=>'001','due_date'=>'2026-12-30','value'=>100],
            ['number'=>'002','due_date'=>'2026-12-05','value'=>110],
        ],JSON_THROW_ON_ERROR);
        $this->post(route('fiscal.nfe.store'),$data)->assertSessionHasErrors('duplicates_json');
        self::assertSame(0,NfeDraft::query()->count());
    }
}
