<?php

namespace Tests\Feature;

use App\Models\CompanySetting;
use App\Models\IncomingNfe;
use App\Models\IncomingNfeSyncState;
use App\Models\User;
use App\Services\Fiscal\IncomingNfeAcbrGateway;
use App\Services\Fiscal\IncomingNfeParser;
use App\Services\Fiscal\IncomingNfeSyncService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\Concerns\WithConfiguredCompany;
use Tests\TestCase;

final class FiscalPurchasesTest extends TestCase
{
    use RefreshDatabase;
    use WithConfiguredCompany;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function accountant(): User
    {
        return User::query()->create([
            'name'=>'Fiscal','email'=>'fiscal-purchases@example.test',
            'password'=>'SenhaSegura123','role'=>'admin','is_active'=>true,
        ]);
    }

    private function key(): string
    {
        return '29261099887766000122550010000001231000001234';
    }

    private function invoiceXml(string $recipient='12345678000199'): string
    {
        $key=$this->key();
        return '<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00"><NFe>'.
            '<infNFe Id="NFe'.$key.'" versao="4.00">'.
            '<ide><cUF>29</cUF><mod>55</mod><serie>1</serie><nNF>123</nNF>'.
            '<dhEmi>2026-10-23T09:00:00-03:00</dhEmi></ide>'.
            '<emit><CNPJ>99887766000122</CNPJ><xNome>Distribuidora Exemplo Ltda</xNome></emit>'.
            '<dest><CNPJ>'.$recipient.'</CNPJ></dest>'.
            '<det nItem="1"><prod><cProd>ABC100</cProd><cEAN>SEM GTIN</cEAN>'.
            '<xProd>Caixa de produtos</xProd><NCM>61091000</NCM><CFOP>5102</CFOP>'.
            '<uCom>UN</uCom><qCom>2.0000</qCom><vUnCom>30.0000</vUnCom>'.
            '<vProd>60.00</vProd></prod></det>'.
            '<total><ICMSTot><vNF>60.00</vNF></ICMSTot></total>'.
            '</infNFe></NFe><protNFe><infProt><cStat>100</cStat></infProt></protNFe></nfeProc>';
    }

    private function ready(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('fiscal/certificates/testing.pfx','fake-cert');
        CompanySetting::current()->update([
            'certificate_path'=>'fiscal/certificates/testing.pfx',
            'certificate_password'=>'SenhaTeste123',
            'certificate_expires_at'=>now()->addYear(),
        ]);
    }

    private function gateway(string $response): object
    {
        $fake=new class($response) extends IncomingNfeAcbrGateway {
            public int $calls=0;
            public array $last=[];
            public function __construct(private string $response) {}
            public function fetch(CompanySetting $company,string $cnpj,string $lastNsu): string
            {
                $this->calls++;
                $this->last=[$cnpj,$lastNsu];
                return $this->response;
            }
        };
        app()->instance(IncomingNfeAcbrGateway::class,$fake);
        return $fake;
    }

    public function test_fiscal_purchases_tab_is_available_without_exposing_any_secret(): void
    {
        $this->actingAs($this->accountant())
            ->get(route('fiscal.index'))
            ->assertOk()
            ->assertSee('Recebidas')
            ->assertSee('>Recebidas</span>',false);
        $this->get(route('fiscal.purchases.index'))
            ->assertOk()
            ->assertSee('Buscar notas')
            ->assertSee('Importar XML')
            ->assertSee('Ativar busca automática');
        $this->assertDatabaseCount('incoming_nfes',0);
    }

    public function test_received_invoice_search_keeps_a_private_diagnostic_out_of_the_operational_page(): void
    {
        $state=app(IncomingNfeSyncService::class)->state();
        $raw='SQLSTATE legacy-private-diagnostic /workspace/private/file.php';
        $state->update(['last_error'=>$raw]);
        $this->actingAs($this->accountant())->get(route('fiscal.purchases.index'))
            ->assertOk()->assertSee('Não foi possível consultar as notas')
            ->assertDontSee('SQLSTATE')->assertDontSee('legacy-private-diagnostic');
        self::assertSame($raw,$state->fresh()->last_error);
    }


    public function test_received_invoices_ui_uses_correct_naming_while_keeping_legacy_routes(): void
    {
        $user=$this->accountant();
        $this->actingAs($user)
            ->get(route('fiscal.purchases.index'))
            ->assertOk()
            ->assertSee('Recebidas')
            ->assertSee('>Recebidas</span>',false);

        $invoice=IncomingNfe::query()->create([
            'access_key'=>'29261099887766000122550010000001231000001234',
            'status'=>'summary',
        ]);
        $this->get(route('fiscal.purchases.show',$invoice))
            ->assertOk()
            ->assertSee('NF-e recebida')
            ->assertSee('Voltar às recebidas')
            ->assertDontSee('Conferência de compra');

        self::assertSame('/fiscal/purchases',route('fiscal.purchases.index',[],false));
        self::assertStringContainsString("@case('inbox')",
            file_get_contents(resource_path('views/partials/icon.blade.php')));
    }

    public function test_purchases_uses_fiscal_table_layout_and_has_no_missing_icons(): void
    {
        $view=file_get_contents(resource_path('views/fiscal/purchases/index.blade.php'));
        $nav=file_get_contents(resource_path('views/fiscal/_nav.blade.php'));
        $icons=file_get_contents(resource_path('views/partials/icon.blade.php'));

        self::assertStringContainsString('class="grid-filter-panel"',$view);
        self::assertStringContainsString('class="toolbar-filters fiscal-purchases-toolbar-filters"',$view);
        self::assertStringContainsString('class="fiscal-empty-state"',$view);
        self::assertStringContainsString('role="switch"',$view);
        self::assertStringNotContainsString('fiscal-purchases-sync-main',$view);
        self::assertStringNotContainsString("'name'=>'file-search'",$view);

        preg_match_all("/'name'=>'([^']+)'/", $view.$nav, $matches);
        foreach (array_unique($matches[1]) as $name) {
            self::assertStringContainsString("@case('".$name."')", $icons, 'Ícone não registrado: '.$name);
        }
    }

    public function test_purchases_month_filter_follows_fiscal_period_and_scopes_totals(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-23 13:00:00'));
        $this->actingAs($this->accountant());
        IncomingNfe::query()->create([
            'access_key'=>'29261099887766000122550010000001231000001234',
            'supplier_name'=>'Mercado Outubro','issued_at'=>'2026-10-02 11:00:00',
            'total'=>'90.50','status'=>'summary',
        ]);
        IncomingNfe::query()->create([
            'access_key'=>'29260899887766000122550010000001231000004567',
            'supplier_name'=>'Fornecedor Agosto','issued_at'=>'2026-08-31 18:00:00',
            'total'=>'488.71','status'=>'summary',
        ]);
        IncomingNfe::query()->create([
            'access_key'=>'29260999887766000122550010000001231000007890',
            'supplier_name'=>'Fornecedor Setembro','issued_at'=>'2026-09-01 00:00:00',
            'total'=>'120.00','status'=>'xml_received',
        ]);

        $this->get(route('fiscal.purchases.index'))
            ->assertOk()->assertSee('Outubro 2026')
            ->assertSee('Mercado Outubro')->assertSee('R$ 90,50')
            ->assertDontSee('Fornecedor Agosto')->assertDontSee('Fornecedor Setembro');

        $this->get(route('fiscal.purchases.index',['month'=>'2026-08']))
            ->assertOk()->assertSee('Agosto 2026')->assertSee('Fornecedor Agosto')
            ->assertSee('R$ 488,71')->assertDontSee('Mercado Outubro')
            ->assertDontSee('Fornecedor Setembro');

        $this->get(route('fiscal.purchases.index',['month'=>'2026-09']))
            ->assertOk()->assertSee('Setembro 2026')->assertSee('Fornecedor Setembro')
            ->assertDontSee('Fornecedor Agosto')->assertDontSee('Mercado Outubro');
    }

    public function test_search_and_status_filter_keep_month_and_invalid_month_defaults_to_current(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-23 13:00:00'));
        $this->actingAs($this->accountant());
        IncomingNfe::query()->create([
            'access_key'=>'29260899887766000122550010000001231000004567',
            'supplier_name'=>'Mercado Agosto','issued_at'=>'2026-08-15 10:00:00',
            'total'=>'50.00','status'=>'summary',
        ]);
        IncomingNfe::query()->create([
            'access_key'=>'29260899887766000122550010000001231000009999',
            'supplier_name'=>'Outro fornecedor','issued_at'=>'2026-08-16 10:00:00',
            'total'=>'60.00','status'=>'xml_received',
        ]);

        $this->get(route('fiscal.purchases.index',[
            'month'=>'2026-08','search'=>'Mercado','status'=>'summary',
        ]))->assertOk()->assertSee('Agosto 2026')->assertSee('Mercado Agosto')
            ->assertDontSee('Outro fornecedor')
            ->assertSee('R$ 50,00')
            ->assertSee('name="month" value="2026-08"',false)
            ->assertSee('month=2026-09')
            ->assertSee('search=Mercado')
            ->assertSee('status=summary');

        $this->get(route('fiscal.purchases.index',['month'=>'2026-13']))
            ->assertOk()->assertSee('Outubro 2026')
            ->assertDontSee('Mercado Agosto');
    }

    public function test_parser_reads_model_55_items_and_refuses_another_recipient(): void
    {
        $parser=app(IncomingNfeParser::class);
        $data=$parser->details($this->invoiceXml(),'12345678000199');
        self::assertSame($this->key(),$data['access_key']);
        self::assertSame('Distribuidora Exemplo Ltda',$data['supplier_name']);
        self::assertSame('61091000',$data['items'][0]['ncm']);
        self::assertSame('2.0000',$data['items'][0]['quantity']);
        $this->expectException(InvalidArgumentException::class);
        $parser->details($this->invoiceXml('11222333000100'),'12345678000199');
    }

    public function test_same_xml_imported_twice_preserves_one_document_and_never_moves_stock(): void
    {
        Storage::fake('local');
        $service=app(IncomingNfeSyncService::class);
        $first=$service->importXml($this->invoiceXml());
        $second=$service->importXml($this->invoiceXml());
        self::assertSame($first->id,$second->id);
        $this->assertDatabaseCount('incoming_nfes',1);
        $this->assertDatabaseCount('stock_movements',0);
        $this->assertDatabaseCount('products',0);
        self::assertSame(1,$second->item_count);
        Storage::disk('local')->assertExists('fiscal/incoming-nfe/'.$this->key().'.xml');

        $this->actingAs($this->accountant())
            ->get(route('fiscal.purchases.show',$first))
            ->assertOk()->assertSee('Caixa de produtos')->assertSee('Baixar XML');
        $this->get(route('fiscal.purchases.xml',$first))
            ->assertOk()->assertDownload('NFe-'.$this->key().'.xml');
    }

    public function test_manual_upload_imports_xml_and_rejects_unrelated_cnpj(): void
    {
        Storage::fake('local');
        $this->actingAs($this->accountant())
            ->post(route('fiscal.purchases.import'),[
                'xml'=>UploadedFile::fake()->createWithContent('entrada.xml',$this->invoiceXml()),
            ])->assertRedirect();
        $this->assertDatabaseCount('incoming_nfes',1);
        $this->post(route('fiscal.purchases.import'),[
            'xml'=>UploadedFile::fake()->createWithContent('indevida.xml',$this->invoiceXml('11222333000100')),
        ])->assertSessionHasErrors('xml');
        $this->assertDatabaseCount('incoming_nfes',1);
    }

    public function test_automatic_sync_requires_real_certificate_and_opt_in(): void
    {
        $this->actingAs($this->accountant())
            ->post(route('fiscal.purchases.automatic'),['auto_sync'=>1])
            ->assertSessionHas('error');
        $this->ready();
        $this->post(route('fiscal.purchases.automatic'),['auto_sync'=>1])
            ->assertSessionHas('success');
        self::assertTrue(app(IncomingNfeSyncService::class)->state()->auto_sync);
        $this->post(route('fiscal.purchases.automatic'),['auto_sync'=>0])
            ->assertSessionHas('success');
        self::assertFalse(app(IncomingNfeSyncService::class)->state()->auto_sync);
    }

    public function test_synchronization_uses_last_nsu_and_never_polls_twice_inside_cooldown(): void
    {
        $this->ready();
        $fake=$this->gateway(
            "[DistribuicaoDFe]\ncStat=138\nultNSU=000000000000003\nmaxNSU=000000000000003\n".
            "[ResNFe001]\nNSU=000000000000003\nchNFe=".$this->key()."\n".
            "CNPJ=99887766000122\nxNome=Distribuidora Exemplo Ltda\n".
            "dEmi=23/10/2026 09:00:00\nvNF=60,00\n"
        );
        $service=app(IncomingNfeSyncService::class);
        $result=$service->synchronize();
        self::assertSame('ok',$result['status']);
        self::assertSame(1,$result['imported']);
        self::assertSame(1,$fake->calls);
        self::assertSame(['12345678000199','000000000000000'],$fake->last);
        $this->assertDatabaseHas('incoming_nfes',[
            'access_key'=>$this->key(),'status'=>'summary',
        ]);
        self::assertSame('000000000000003',$service->state()->last_nsu);
        self::assertSame('cooldown',$service->synchronize()['status']);
        self::assertSame(1,$fake->calls);
    }

    public function test_received_full_xml_upgrades_summary_without_duplicate_or_stock_movement(): void
    {
        $this->ready();
        IncomingNfe::query()->create(['access_key'=>$this->key(),'status'=>'summary']);
        $fake=$this->gateway(
            "[DistribuicaoDFe]\ncStat=138\nultNSU=000000000000004\nmaxNSU=000000000000004\n".
            "[NFe001]\nNSU=000000000000004\nchNFe=".$this->key()."\n".
            "XML=".$this->invoiceXml()."\n"
        );
        self::assertSame('ok',app(IncomingNfeSyncService::class)->synchronize()['status']);
        self::assertSame(1,$fake->calls);
        $this->assertDatabaseCount('incoming_nfes',1);
        $this->assertDatabaseHas('incoming_nfes',['access_key'=>$this->key(),'status'=>'xml_received','item_count'=>1]);
        $this->assertDatabaseCount('stock_movements',0);
    }

    public function test_consumo_indevido_656_preserves_nsu_and_blocks_new_calls(): void
    {
        $this->ready();
        $fake=$this->gateway(
            "[DistribuicaoDFe]\ncStat=656\nultNSU=000000000000000\nmaxNSU=000000000000003\nxMotivo=Consumo indevido"
        );
        $service=app(IncomingNfeSyncService::class);
        self::assertSame('error',$service->synchronize()['status']);
        self::assertSame('000000000000000',$service->state()->last_nsu);
        self::assertTrue($service->state()->next_check_at->isFuture());
        self::assertSame('cooldown',$service->synchronize()['status']);
        self::assertSame(1,$fake->calls);
    }

    public function test_no_documents_137_schedules_next_hour_instead_of_repeating_calls(): void
    {
        $this->ready();
        $fake=$this->gateway(
            "[DistribuicaoDFe]\ncStat=137\nultNSU=000000000000000\nmaxNSU=000000000000000"
        );
        $service=app(IncomingNfeSyncService::class);
        self::assertSame('ok',$service->synchronize()['status']);
        self::assertSame('cooldown',$service->synchronize()['status']);
        self::assertSame(1,$fake->calls);
    }

    public function test_scheduled_command_respects_opt_in_and_the_saved_sefaz_cooldown(): void
    {
        $this->ready();
        $fake=$this->gateway(
            "[DistribuicaoDFe]\ncStat=137\nultNSU=000000000000000\nmaxNSU=000000000000000"
        );
        $state=app(IncomingNfeSyncService::class)->state();
        self::assertFalse($state->auto_sync);
        self::assertSame(0,\Illuminate\Support\Facades\Artisan::call('fiscal:sync-incoming-nfe'));
        self::assertSame(0,$fake->calls);

        $state->update(['auto_sync'=>true]);
        self::assertSame(0,\Illuminate\Support\Facades\Artisan::call('fiscal:sync-incoming-nfe'));
        self::assertSame(1,$fake->calls);
        self::assertSame('000000000000000',$state->fresh()->last_nsu);
        self::assertSame(0,\Illuminate\Support\Facades\Artisan::call('fiscal:sync-incoming-nfe'));
        self::assertSame(1,$fake->calls);
    }


    public function test_acbr_resdfe_envelope_and_case_variants_are_recognized(): void
    {
        $parsed=app(IncomingNfeParser::class)->envelope(
            "[DistribuicaoDFe]\nCStat=138\nultNSU=7\nmaxNSU=7\n".
            "[ResDFe001]\nNSU=7\nchDFe=".$this->key()."\n".
            "CNPJ=99887766000122\nxNome=Fornecedor de Teste\nvNF=60,00\n"
        );
        self::assertSame('138',$parsed['status']);
        self::assertSame('000000000000007',$parsed['last_nsu']);
        self::assertCount(1,$parsed['documents']);
        self::assertSame($this->key(),$parsed['documents'][0]['access_key']);

        $cteKey=substr_replace($this->key(),'57',20,2);
        $other=app(IncomingNfeParser::class)->envelope(
            "[DistribuicaoDFe]\ncStat=138\nultNSU=8\nmaxNSU=8\n".
            "[ResDFe001]\nNSU=8\nchDFe=".$cteKey."\n"
        );
        self::assertCount(0,$other['documents']);
    }

    public function test_sefaz_656_without_nsu_still_applies_cooldown_without_advancing_cursor(): void
    {
        $this->ready();
        $fake=$this->gateway("[DistribuicaoDFe]\ncStat=656\nxMotivo=Consumo indevido");
        $service=app(IncomingNfeSyncService::class);
        self::assertSame('error',$service->synchronize()['status']);
        self::assertSame('000000000000000',$service->state()->last_nsu);
        self::assertTrue($service->state()->next_check_at->isFuture());
        self::assertSame('cooldown',$service->synchronize()['status']);
        self::assertSame(1,$fake->calls);
    }

    public function test_xml_download_and_sync_are_protected_by_fiscal_permission(): void
    {
        $user=User::query()->create([
            'name'=>'Operador','email'=>'no-fiscal@example.test',
            'password'=>'SenhaSegura123','role'=>'operator',
            'permissions'=>['pdv'],'is_active'=>true,
        ]);
        $this->actingAs($user)->get(route('fiscal.purchases.index'))->assertForbidden();
        $this->post(route('fiscal.purchases.sync'))->assertForbidden();
        $this->post(route('fiscal.purchases.import'))->assertForbidden();
    }
}
