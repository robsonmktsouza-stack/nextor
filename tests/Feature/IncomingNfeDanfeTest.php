<?php

namespace Tests\Feature;

use App\Models\IncomingNfe;
use App\Models\User;
use App\Services\Fiscal\IncomingNfeDanfeService;
use App\Services\Fiscal\IncomingNfeSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\WithConfiguredCompany;
use Tests\TestCase;

final class IncomingNfeDanfeTest extends TestCase
{
    use RefreshDatabase;
    use WithConfiguredCompany;

    private function key(): string
    {
        return '29261099887766000122550010000001231000001234';
    }

    private function user(string $role='admin'): User
    {
        return User::query()->create([
            'name'=>'Contador DANFE','email'=>uniqid('danfe-').'@example.test',
            'password'=>'safe-test-password','role'=>$role,
            'permissions'=>$role==='operator'?['pdv']:null,
            'is_active'=>true,
        ]);
    }

    private function authorizedXml(string $cnpj='12345678000199'): string
    {
        $key=$this->key();
        return '<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">'.
            '<NFe><infNFe Id="NFe'.$key.'" versao="4.00">'.
            '<ide><mod>55</mod><tpAmb>1</tpAmb><serie>1</serie><nNF>123</nNF>'.
            '<dhEmi>2026-10-23T09:00:00-03:00</dhEmi></ide>'.
            '<emit><CNPJ>99887766000122</CNPJ><xNome>Distribuidora</xNome></emit>'.
            '<dest><CNPJ>'.$cnpj.'</CNPJ></dest>'.
            '<det nItem="1"><prod><cProd>ABC</cProd><xProd>Produto</xProd><NCM>61091000</NCM>'.
            '<CFOP>5102</CFOP><uCom>UN</uCom><qCom>2.0000</qCom>'.
            '<vUnCom>30.0000</vUnCom><vProd>60.00</vProd></prod></det>'.
            '<total><ICMSTot><vNF>60.00</vNF></ICMSTot></total>'.
            '</infNFe></NFe>'.
            '<protNFe><infProt><tpAmb>1</tpAmb><chNFe>'.$key.'</chNFe>'.
            '<cStat>100</cStat><nProt>129260000123456</nProt></infProt></protNFe>'.
            '</nfeProc>';
    }

    private function fakePdf(): object
    {
        $fake=new class(app(IncomingNfeSyncService::class)) extends IncomingNfeDanfeService {
            public int $renders=0;
            protected function render(string $xml,\App\Models\CompanySetting $company): string
            {
                $this->renders++;
                return "%PDF-1.4\n%mock fixture";
            }
        };
        app()->instance(IncomingNfeDanfeService::class,$fake);
        return $fake;
    }

    public function test_authorized_xml_can_be_opened_as_danfe_without_creating_stock_or_payables(): void
    {
        Storage::fake('local');
        $invoice=app(IncomingNfeSyncService::class)->importXml($this->authorizedXml());
        $pdf=$this->fakePdf();
        $this->actingAs($this->user())
            ->get(route('fiscal.purchases.danfe',$invoice))
            ->assertOk()
            ->assertHeader('Content-Type','application/pdf')
            ->assertHeader('X-Content-Type-Options','nosniff');
        self::assertSame(1,$pdf->renders);
        $this->assertDatabaseCount('stock_movements',0);
        $this->assertDatabaseCount('products',0);
        $this->get(route('fiscal.purchases.index'))->assertSee('Valor total')
            ->assertSee('R$ 60,00')->assertSee('Imprimir DANFE');
        $this->get(route('fiscal.purchases.show',$invoice))->assertSee('Imprimir DANFE');
    }

    public function test_summary_without_xml_cannot_be_downloaded_or_printed(): void
    {
        Storage::fake('local');
        $invoice=IncomingNfe::query()->create(['access_key'=>$this->key(),'status'=>'summary','issued_at'=>now(),'total'=>'99.99']);
        $pdf=$this->fakePdf();
        $this->actingAs($this->user())
            ->get(route('fiscal.purchases.danfe',$invoice))->assertNotFound();
        $this->get(route('fiscal.purchases.xml',$invoice))->assertNotFound();
        $this->get(route('fiscal.purchases.index'))
            ->assertSee('R$ 99,99')
            ->assertSee('Imprimir DANFE indisponível');
        self::assertSame(0,$pdf->renders);
    }

    public function test_danfe_rejects_xml_without_approved_protocol_or_other_recipient(): void
    {
        Storage::fake('local');
        $invoice=app(IncomingNfeSyncService::class)->importXml($this->authorizedXml());
        $pdf=$this->fakePdf();
        $path='fiscal/incoming-nfe/'.$this->key().'.xml';
        $this->actingAs($this->user());
        Storage::disk('local')->put($path,str_replace('<cStat>100</cStat>','<cStat>101</cStat>',$this->authorizedXml()));
        $this->get(route('fiscal.purchases.danfe',$invoice))->assertStatus(409);
        Storage::disk('local')->put($path,$this->authorizedXml('11222333000100'));
        $this->get(route('fiscal.purchases.danfe',$invoice))->assertStatus(409);
        Storage::disk('local')->put($path,str_replace('<nProt>129260000123456</nProt>','<nProt></nProt>',$this->authorizedXml()));
        $this->get(route('fiscal.purchases.danfe',$invoice))->assertStatus(409);
        self::assertSame(0,$pdf->renders);
    }

    public function test_print_route_requires_fiscal_permission(): void
    {
        $invoice=IncomingNfe::query()->create(['access_key'=>$this->key(),'status'=>'summary']);
        $this->actingAs($this->user('operator'))
            ->get(route('fiscal.purchases.danfe',$invoice))->assertForbidden();
    }
}
