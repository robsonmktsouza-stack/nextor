<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\FiscalDocumentJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class NFCePrintSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::query()->create([
            'name'=>'Administrador',
            'email'=>'nfce-print-settings@example.com',
            'password'=>'senhaSegura123',
            'role'=>'admin',
            'is_active'=>true,
        ]);
    }

    private function document(): FiscalDocumentJob
    {
        $job=FiscalDocumentJob::query()->create([
            'document_type'=>'nfce',
            'status'=>'authorized',
            'environment'=>'homologation',
            'series'=>1,
            'document_number'=>1,
            'access_key'=>str_repeat('1',44),
            'protocol'=>str_repeat('2',15),
            'prepared_at'=>now(),
            'authorized_at'=>now(),
        ]);
        $key=str_repeat('1',44);
        $protocol=str_repeat('2',15);
        $xml='<?xml version="1.0" encoding="UTF-8"?>'
            .'<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">'
            .'<NFe><infNFe Id="NFe'.$key.'" versao="4.00">'
            .'<ide><mod>65</mod><serie>1</serie><nNF>1</nNF><tpAmb>2</tpAmb>'
            .'<dhEmi>2026-10-08T13:55:35-03:00</dhEmi></ide>'
            .'<emit><CNPJ>39323356000100</CNPJ><xNome>EMITENTE TESTE</xNome>'
            .'<enderEmit><xLgr>RUA TESTE</xLgr><nro>10</nro><xBairro>CENTRO</xBairro>'
            .'<xMun>Rio do Antonio</xMun><UF>BA</UF><CEP>46220000</CEP></enderEmit></emit>'
            .'<det nItem="1"><prod><cProd>ITEM-1</cProd><xProd>PRODUTO DE TESTE</xProd>'
            .'<qCom>1.000</qCom><uCom>UN</uCom><vUnCom>12.9000</vUnCom><vProd>12.90</vProd></prod></det>'
            .'<total><ICMSTot><vProd>12.90</vProd><vNF>12.90</vNF></ICMSTot></total>'
            .'<pag><detPag><tPag>01</tPag><vPag>12.90</vPag></detPag></pag>'
            .'</infNFe><infNFeSupl><qrCode>http://hnfe.sefaz.ba.gov.br/servicos/nfce/qrcode.aspx?p='
            .$key.'|2|2|1|HASH</qrCode>'
            .'<urlChave>http://hinternet.sefaz.ba.gov.br/nfce/consulta</urlChave>'
            .'</infNFeSupl></NFe>'
            .'<protNFe versao="4.00"><infProt><tpAmb>2</tpAmb><chNFe>'.$key.'</chNFe>'
            .'<nProt>'.$protocol.'</nProt><cStat>100</cStat>'
            .'<dhRecbto>2026-10-08T13:55:37-03:00</dhRecbto>'
            .'</infProt></protNFe></nfeProc>';

        $path='fiscal/nfce/'.$job->id.'/authorized.xml';
        Storage::disk('local')->put($path,$xml);
        $job->update(['xml_path'=>$path]);
        return $job->fresh();
    }

    public function test_fiscal_print_paper_is_selected_and_persisted_in_settings(): void
    {
        $this->actingAs($this->admin());
        foreach (['58','80','a4'] as $paper) {
            $this->post(route('settings.printing.update'),['nfce_paper'=>$paper])
                ->assertSessionHasNoErrors();
            self::assertSame($paper,AppSetting::value('printing','nfce_paper'));
            $this->get(route('settings.index',['tab'=>'printing']))
                ->assertOk()
                ->assertSee('name="nfce_paper"',false)
                ->assertSee('value="'.$paper.'" selected',false);
        }

        $this->post(route('settings.printing.update'),['nfce_paper'=>'custom-300'])
            ->assertSessionHasErrors('nfce_paper');
    }

    public function test_nfce_uses_saved_paper_and_opens_native_browser_print_without_toolbar(): void
    {
        Storage::fake('local');
        $this->actingAs($this->admin());
        $job=$this->document();

        foreach (['58','80','a4'] as $paper) {
            AppSetting::put('printing','nfce_paper',$paper);
            $this->get(route('fiscal.nfce.danfe',['fiscalDocumentJob'=>$job,'paper'=>'58']))
                ->assertOk()
                ->assertSee('data-paper="'.$paper.'"',false)
                ->assertSee('window.print()',false)
                ->assertDontSee('danfe-tools',false)
                ->assertDontSee('printDanfe',false)
                ->assertDontSee('80 mm (72 mm úteis)');
        }
    }

    public function test_previous_receipt_width_remains_fallback_until_paper_is_configured(): void
    {
        Storage::fake('local');
        $this->actingAs($this->admin());
        $job=$this->document();
        AppSetting::put('pdv','receipt_width','58');

        $this->get(route('fiscal.nfce.danfe',$job))
            ->assertOk()->assertSee('data-paper="58"',false);
    }
}
