<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\FiscalDocumentJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Queue;
use App\Jobs\GenerateNFCeDanfePdfJob;
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

    public function test_cached_nfce_pdf_opens_in_native_chrome_viewer_without_loading_acbr_in_web(): void
    {
        Storage::fake('local');
        $this->actingAs($this->admin());
        $job=$this->document();
        $pdf=app(\App\Services\Fiscal\NFCePdfService::class);
        $xml=Storage::disk('local')->get($job->xml_path);

        foreach (['58','80','a4'] as $paper) {
            AppSetting::put('printing','nfce_paper',$paper);
            $path=$pdf->storedPath($job,$xml);
            Storage::disk('local')->put($path,"%PDF-1.7\nTEST PDF\n%%EOF");
            $this->get(route('fiscal.nfce.danfe',['fiscalDocumentJob'=>$job,'paper'=>'58']))
                ->assertOk()
                ->assertHeader('Content-Type','application/pdf')
                ->assertHeader('Content-Disposition','inline; filename="DANFE-NFCe-1-1.pdf"');
        }
    }

    public function test_missing_pdf_is_generated_on_first_open_without_a_queue_worker(): void
    {
        Storage::fake('local');
        Queue::fake();
        config()->set('queue.default','database');
        $this->actingAs($this->admin());
        $job=$this->document();

        $renderer=\Mockery::mock(\App\Services\Fiscal\NFCePdfService::class)->makePartial();
        $renderer->shouldReceive('generate')->once()
            ->andReturnUsing(function($document,$xml) use ($renderer) {
                $path=$renderer->storedPath($document,$xml);
                Storage::disk('local')->put($path,"%PDF-1.7\nTEST PDF\n%%EOF");
                return $path;
            });
        $this->app->instance(\App\Services\Fiscal\NFCePdfService::class,$renderer);

        $this->get(route('fiscal.nfce.danfe',$job))
            ->assertOk()
            ->assertHeader('Content-Type','application/pdf');
        Queue::assertNothingPushed();

        // Opening again takes the saved fast path.
        $this->get(route('fiscal.nfce.danfe',$job))
            ->assertOk()
            ->assertHeader('Content-Type','application/pdf');
    }

    public function test_failed_pdf_render_displays_retry_without_a_long_loading_screen(): void
    {
        Storage::fake('local');
        $this->actingAs($this->admin());
        $job=$this->document();

        $renderer=\Mockery::mock(\App\Services\Fiscal\NFCePdfService::class)->makePartial();
        $renderer->shouldReceive('generate')->once()
            ->andThrow(new \RuntimeException('Falha controlada na conversão PDF'));
        $this->app->instance(\App\Services\Fiscal\NFCePdfService::class,$renderer);

        $this->get(route('fiscal.nfce.danfe',$job))
            ->assertOk()
            ->assertSee('Não foi possível preparar o DANFE.')
            ->assertSee('[hidden]{display:none!important}',false)
            ->assertSee('Tentar novamente');

        $this->get(route('fiscal.nfce.danfe',['fiscalDocumentJob'=>$job,'status'=>1]))
            ->assertOk()->assertJson(['status'=>'failed']);
    }

    public function test_pdf_worker_persists_valid_real_pdf_without_retransmitting_nfce(): void
    {
        Storage::fake('local');
        $job=$this->document();
        $xml=Storage::disk('local')->get($job->xml_path);
        $renderer=\Mockery::mock(\App\Services\Fiscal\NFCePdfService::class)->makePartial();
        $renderer->shouldReceive('render')
            ->once()->andReturn("%PDF-1.7\nPDF GENERATED\n%%EOF");
        $this->app->instance(\App\Services\Fiscal\NFCePdfService::class,$renderer);

        app(GenerateNFCeDanfePdfJob::class,['fiscalDocumentJobId'=>$job->id])
            ->handle($renderer,app(\App\Services\Fiscal\NFCeDanfeService::class));

        $path=$renderer->storedPath($job,$xml);
        Storage::disk('local')->assertExists($path);
        self::assertSame('authorized',$job->fresh()->status);
    }

    public function test_pdf_source_is_the_original_nextor_danfe_with_its_css_and_qr(): void
    {
        Storage::fake('local');
        $job=$this->document();
        $xml=Storage::disk('local')->get($job->xml_path);
        $pdf=app(\App\Services\Fiscal\NFCePdfService::class);
        AppSetting::put('printing','nfce_paper','80');

        $html=$pdf->renderHtml($job,$xml);

        self::assertStringContainsString('data-paper="80"',$html);
        self::assertStringContainsString('class="danfe-paper"',$html);
        self::assertStringContainsString('class="danfe-item-head"',$html);
        self::assertStringContainsString('PRODUTO DE TESTE',$html);
        self::assertStringContainsString('VALOR A PAGAR',$html);
        self::assertStringContainsString('id="danfeQr"',$html);
        self::assertStringContainsString('qr.createSvgTag',$html);
        self::assertStringContainsString('.danfe-issuer{',$html);
        self::assertStringNotContainsString('window.print()',$html);
        self::assertStringNotContainsString('TipoRelatorioBobina',$html);
        self::assertStringNotContainsString('<link rel="stylesheet"',$html);
    }

    public function test_original_danfe_can_render_real_pdf_with_local_chrome_when_installed(): void
    {
        $paths=['/usr/bin/google-chrome','/usr/bin/chromium','/usr/bin/chromium-browser'];
        if (!collect($paths)->contains(fn($path)=>is_file($path))) {
            $this->markTestSkipped('A prova de conversão real em PDF exige Chromium no runner.');
        }

        Storage::fake('local');
        $job=$this->document();
        $xml=Storage::disk('local')->get($job->xml_path);
        $pdf=app(\App\Services\Fiscal\NFCePdfService::class);

        $bytes=$pdf->render($job,$xml);
        self::assertStringStartsWith('%PDF-',$bytes);
        self::assertStringContainsString('%%EOF',substr($bytes,-1024));
        self::assertGreaterThan(6000,strlen($bytes));
    }

    public function test_previous_receipt_width_remains_fallback_until_paper_is_configured(): void
    {
        Storage::fake('local');
        $this->actingAs($this->admin());
        $job=$this->document();
        AppSetting::put('pdv','receipt_width','58');

        self::assertSame('58',app(\App\Services\Fiscal\NFCePdfService::class)->paper());

        AppSetting::put('printing','nfce_paper','a4');
        self::assertSame('a4',app(\App\Services\Fiscal\NFCePdfService::class)->paper());
    }
}
