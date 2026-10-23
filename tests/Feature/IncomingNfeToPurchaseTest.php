<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\IncomingNfe;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\User;
use App\Services\Fiscal\IncomingNfeSyncService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\WithConfiguredCompany;
use Tests\TestCase;

final class IncomingNfeToPurchaseTest extends TestCase
{
    use RefreshDatabase;
    use WithConfiguredCompany;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function key(): string
    {
        return '29261099887766000122550010000001231000001234';
    }

    private function xml(string $key): string
    {
        return '<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00"><NFe>'
            .'<infNFe Id="NFe'.$key.'" versao="4.00">'
            .'<ide><mod>55</mod><serie>1</serie><nNF>123</nNF><dhEmi>2026-10-23T09:00:00-03:00</dhEmi></ide>'
            .'<emit><CNPJ>99887766000122</CNPJ><xNome>Fornecedor Exemplo Ltda</xNome></emit>'
            .'<dest><CNPJ>12345678000199</CNPJ></dest>'
            .'<det nItem="1"><prod><cProd>ABC100</cProd><cEAN>SEM GTIN</cEAN>'
            .'<xProd>Caixa de produtos</xProd><uCom>UN</uCom><qCom>2.0000</qCom>'
            .'<vUnCom>30.0000</vUnCom><vProd>60.00</vProd><vDesc>2.00</vDesc>'
            .'</prod><imposto><IPI><IPITrib><vIPI>3.00</vIPI></IPITrib></IPI></imposto></det>'
            .'<total><ICMSTot><vProd>60.00</vProd><vDesc>2.00</vDesc><vIPI>3.00</vIPI>'
            .'<vFrete>5.00</vFrete><vOutro>2.00</vOutro><vNF>68.00</vNF></ICMSTot></total>'
            .'</infNFe></NFe><protNFe><infProt><cStat>100</cStat></infProt></protNFe></nfeProc>';
    }

    private function admin(): User
    {
        return User::query()->create([
            'name'=>'Importador fiscal','email'=>'purchases-incoming@example.test',
            'password'=>'password','role'=>'admin','is_active'=>true,
        ]);
    }

    public function test_fiscal_action_opens_real_purchase_prefilled_from_xml_without_side_effects(): void
    {
        Carbon::setTestNow('2026-10-23 13:00:00');
        Storage::fake('local');
        $this->actingAs($this->admin());
        $supplier=Customer::query()->create([
            'name'=>'Fornecedor Exemplo Ltda','document'=>'99.887.766/0001-22',
            'is_supplier'=>true,
        ]);
        $product=Product::query()->create([
            'sku'=>'ABC100','name'=>'Caixa de produtos','unit'=>'UN','is_active'=>true,
            'cost_price'=>'20.00','sale_price'=>'40.00','control_stock'=>true,
            'stock_quantity'=>'5.000',
        ]);
        $invoice=app(IncomingNfeSyncService::class)->importXml($this->xml($this->key()));
        $this->get(route('fiscal.purchases.index',['month'=>'2026-10']))
            ->assertOk()
            ->assertSee('aria-label="Importar NF-e para Compras"',false)
            ->assertSee(route('purchases.import-from-nfe',$invoice),false)
            ->assertDontSee('aria-label="Detalhes da NF-e"',false);

        $this->get(route('purchases.import-from-nfe',$invoice))
            ->assertOk()->assertSee('Caixa de produtos')
            ->assertSee('name="incoming_nfe_id"',false)
            ->assertSee('name="supplier_id"',false)
            ->assertSee('"source_description":"Caixa de produtos"',false)
            ->assertSee('"product_id":'.$product->id,false)
            ->assertSee('data-purchase-add',false);
        $this->assertDatabaseCount('purchases',0);
        $this->assertDatabaseCount('stock_movements',0);
        $this->assertDatabaseCount('financial_entries',0);

        $this->post(route('purchases.store'),[
            'supplier_id'=>$supplier->id,
            'incoming_nfe_id'=>$invoice->id,
            'operation_date'=>'2026-10-23','due_date'=>null,
            'document_number'=>'123','freight'=>'5.00','discount'=>'0.00',
            'other_expenses'=>'2.00','generate_payable'=>'0',
            'items'=>[[
                'product_id'=>$product->id,'quantity'=>'2.000','unit_cost'=>'30.00',
                'discount'=>'2.00','other_costs'=>'0.00',
                'ipi'=>'3.00','icms_st'=>'0.00','external_costs'=>'0.00',
            ]],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $purchase=Purchase::query()->firstOrFail();
        self::assertSame($invoice->id,$purchase->incoming_nfe_id);
        self::assertSame('68.00',$purchase->total);
        self::assertSame('draft',$purchase->status);
        $this->assertDatabaseCount('stock_movements',0);

        $this->get(route('purchases.import-from-nfe',$invoice))
            ->assertRedirect(route('purchases.show',$purchase));
        $this->get(route('fiscal.purchases.index',['month'=>'2026-10']))
            ->assertSee('Abrir compra vinculada')
            ->assertDontSee('aria-label="Importar NF-e para Compras"',false);
    }

    public function test_import_action_is_disabled_until_sefaz_xml_is_available(): void
    {
        Carbon::setTestNow('2026-10-23 13:00:00');
        Storage::fake('local');
        $this->actingAs($this->admin());
        $invoice=IncomingNfe::query()->create([
            'access_key'=>$this->key(),'status'=>'summary','total'=>'68.00',
            'issued_at'=>'2026-10-23 09:00:00',
        ]);
        $listing=fn()=> $this->get(route('fiscal.purchases.index',['month'=>'2026-10']));
        $listing()->assertOk()
            ->assertSee('aria-label="Importar para Compras indisponível"',false)
            ->assertSee('XML ainda não recebido')
            ->assertDontSee('data-import-purchase-invoice',false)
            ->assertDontSee('aria-label="Importar NF-e para Compras"',false);
        $this->get(route('purchases.import-from-nfe',$invoice))
            ->assertRedirect()->assertSessionHasErrors('xml');

        // A row marked as complete is still unavailable when its XML is missing.
        $invoice->update([
            'status'=>'xml_received',
            'xml_path'=>'fiscal/incoming-nfe/'.$this->key().'.xml',
        ]);
        $listing()->assertOk()
            ->assertSee('aria-label="Importar para Compras indisponível"',false)
            ->assertDontSee('aria-label="Importar NF-e para Compras"',false);

        // When synchronization delivers the full XML, the action becomes usable.
        $invoice=app(IncomingNfeSyncService::class)->importXml($this->xml($this->key()));
        $listing()->assertOk()
            ->assertSee(route('purchases.import-from-nfe',$invoice),false)
            ->assertSee('aria-label="Importar NF-e para Compras"',false)
            ->assertDontSee('aria-label="Importar para Compras indisponível"',false);
        $this->assertDatabaseCount('purchases',0);
    }
}
