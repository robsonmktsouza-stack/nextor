<?php

namespace Tests\Feature;

use App\Models\Sale;
use App\Models\User;
use App\Models\PaymentMethod;
use App\Models\FinancialEntry;
use App\Models\StockMovement;
use App\Services\SaleReceiptPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SaleReceiptReuseTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\WithConfiguredCompany;

    private function fakePdfRenderer(): void
    {
        $mock=\Mockery::mock(SaleReceiptPdfService::class);
        $mock->shouldReceive('render')
            ->andReturn("%PDF-1.4\nComprovante de teste\n%%EOF");
        $this->app->instance(SaleReceiptPdfService::class,$mock);
    }

    private function operator(array $permissions=['sales']): User
    {
        return User::create([
            'name'=>'Vendedor de teste',
            'email'=>'vendedor-'.implode('-',$permissions).'@example.test',
            'password'=>'senhaSegura123',
            'role'=>'operator',
            'permissions'=>$permissions,
            'is_active'=>true,
        ]);
    }

    private function sale(User $user, string $type='sale', string $source='manual'): Sale
    {
        $sale=Sale::create([
            'user_id'=>$user->id,
            'operation_type'=>$type,
            'source'=>$source,
            'operation_date'=>now()->toDateString(),
            'final_consumer'=>true,
            'status'=>'completed',
            'subtotal'=>'70.00',
            'discount_total'=>'5.00',
            'total'=>'65.00',
            'completed_at'=>now(),
            'notes'=>'Entregar no balcão',
        ]);
        $sale->items()->create([
            'item_type'=>'product',
            'product_name'=>'Produto para balcão',
            'product_sku'=>'BAL-001',
            'quantity'=>'2.000','unit_price'=>'35.00',
            'discount'=>'5.00','line_total'=>'65.00',
        ]);
        return $sale;
    }

    public function test_regular_sale_opens_a_private_native_pdf_using_the_pdv_thermal_template(): void
    {
        $user=$this->operator(['sales']);
        $sale=$this->sale($user);
        PaymentMethod::firstOrCreate(['code'=>'pix'],[
            'name'=>'PIX','kind'=>'pix','is_active'=>true,'pdv_enabled'=>true,
        ]);
        $sale->payments()->create([
            'installment'=>1,'amount'=>'65.00',
            'payment_method'=>'pix','receivable'=>false,
        ]);

        $this->actingAs($user);
        $html=app(SaleReceiptPdfService::class)->renderHtml($sale);
        self::assertStringContainsString('COMPROVANTE DE VENDA',$html);
        self::assertStringContainsString('SEM VALOR FISCAL',$html);
        self::assertStringContainsString('BAL-001',$html);
        self::assertStringContainsString('Produto para balcão',$html);
        self::assertStringContainsString('65,00',$html);
        self::assertStringContainsString('PIX',$html);
        self::assertStringContainsString('@page{size:80mm ',$html);
        self::assertStringNotContainsString('Voltar à venda',$html);
        self::assertStringNotContainsString('Imprimir comprovante interno',$html);
        self::assertStringNotContainsString('<script>',$html);
        self::assertStringNotContainsString('NFC-e da venda',$html);

        $this->fakePdfRenderer();
        $this->get(route('sales.receipt',$sale))
            ->assertOk()
            ->assertHeader('Content-Type','application/pdf')
            ->assertHeader('Content-Disposition','inline; filename="Comprovante-Venda-'.str_pad((string)$sale->id,5,'0',STR_PAD_LEFT).'.pdf"')
            ->assertHeader('Cache-Control','max-age=0, no-store, private')
            ->assertHeader('X-Content-Type-Options','nosniff')
            ->assertSee('%PDF-1.4',false);

        $this->get(route('sales.receipt',['sale'=>$sale,'download'=>1]))
            ->assertOk()
            ->assertHeader('Content-Disposition','attachment; filename="Comprovante-Venda-'.str_pad((string)$sale->id,5,'0',STR_PAD_LEFT).'.pdf"');

        $this->get(route('sales.show',$sale))->assertOk()
            ->assertSee('Comprovante de venda')
            ->assertSee(route('sales.receipt',$sale),false)
            ->assertSee('target="_blank" rel="noopener noreferrer"',false);
        $this->get(route('sales.index'))->assertOk()
            ->assertSee('Imprimir comprovante de venda');

        self::assertSame(1,Sale::count());
        self::assertSame(0,StockMovement::count());
        self::assertSame(0,FinancialEntry::count());
    }

    public function test_sales_receipt_bookmarked_urls_always_open_native_pdf_without_print_dialog(): void
    {
        $seller=$this->operator(['sales']);
        $sale=$this->sale($seller);
        $this->actingAs($seller);
        $this->fakePdfRenderer();

        foreach ([
            route('sales.receipt',$sale),
            route('sales.receipt',['sale'=>$sale,'print'=>0]),
            route('sales.receipt',['sale'=>$sale,'print'=>1]),
        ] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertHeader('Content-Type','application/pdf')
                ->assertHeader('Cache-Control','max-age=0, no-store, private');
        }
        $this->get(route('sales.index'))->assertOk()
            ->assertSee(route('sales.receipt',$sale),false);
    }

    public function test_receipt_is_not_exposed_for_quotes_and_does_not_change_pdv_source_rules(): void
    {
        $user=$this->operator(['sales']);
        $quote=$this->sale($user,'quote');

        $this->actingAs($user)
            ->get(route('sales.receipt',$quote))->assertNotFound();
        $this->get(route('sales.show',$quote))->assertOk()
            ->assertDontSee('Comprovante de venda');

        $normal=$this->sale($user,'sale','manual');
        $this->get(route('pdv.receipt',$normal))->assertForbidden();
        $this->fakePdfRenderer();
        $this->get(route('sales.receipt',$normal))
            ->assertOk()->assertHeader('Content-Type','application/pdf');
    }

    public function test_receipt_requires_authentication_and_sales_permission(): void
    {
        $seller=$this->operator(['sales']);
        $sale=$this->sale($seller);
        auth()->logout();
        $this->get(route('sales.receipt',$sale))->assertRedirect('/login');

        $pdvOnly=$this->operator(['pdv']);
        $this->actingAs($pdvOnly)
            ->get(route('sales.receipt',$sale))->assertForbidden();
        $this->get(route('pdv.receipt',$sale))->assertNotFound();
    }

    public function test_pdv_history_opens_pdf_but_new_sale_keeps_automatic_print(): void
    {
        $cashier=$this->operator(['pdv']);
        $sale=$this->sale($cashier,'sale','pdv');
        $this->actingAs($cashier);

        // A finalização sem ?print=0 continua no recibo HTML com retorno ao PDV.
        $this->get(route('pdv.receipt',$sale))
            ->assertOk()
            ->assertViewIs('pdv.receipt')
            ->assertSee('Voltar ao PDV')
            ->assertSee('let automatic=true;',false)
            ->assertSee('NFC-e ainda não preparada')
            ->assertSee('SEM VALOR FISCAL');

        $this->fakePdfRenderer();
        $this->get(route('pdv.receipt',['sale'=>$sale,'print'=>0]))
            ->assertOk()
            ->assertHeader('Content-Type','application/pdf')
            ->assertHeader('Content-Disposition','inline; filename="Comprovante-Venda-'.str_pad((string)$sale->id,5,'0',STR_PAD_LEFT).'.pdf"')
            ->assertHeader('Cache-Control','max-age=0, no-store, private');
        $this->get(route('sales.receipt',$sale))->assertForbidden();

        // Falha na preparação fiscal ainda mostra a explicação ao operador.
        $this->get(route('pdv.receipt',['sale'=>$sale,'print'=>0,'status'=>1]))
            ->assertOk()->assertViewIs('pdv.receipt')
            ->assertSee('let automatic=false;',false)
            ->assertSee('NFC-e ainda não preparada');
    }

    public function test_checkout_receipt_opens_inside_pdv_and_printing_requires_operator_action(): void
    {
        $cashier=$this->operator(['pdv']);
        $sale=$this->sale($cashier,'sale','pdv');
        $this->actingAs($cashier);

        $this->withSession([
            'pdv_receipt_sale'=>$sale->id,
            'pdv_last_sale'=>$sale->id,
        ])->get(route('pdv.index'))
            ->assertOk()
            ->assertSee('id="pdvReceiptModal"',false)
            ->assertSee('data-receipt-after-checkout="1"',false)
            ->assertSee('id="pdvReceiptFrame"',false)
            ->assertSee('embed=1',false)
            ->assertSee('Venda efetuada com sucesso')
            ->assertSee('Voltar ao PDV')
            ->assertSee('Imprimir comprovante')
            ->assertSee('class="pdv-receipt-items"',false)
            ->assertSee('Produto para balcão')
            ->assertSee('R$ 65,00')
            ->assertSee('Comprovante interno de venda')
            ->assertSee('class="pdv-receipt-print-frame"',false)
            ->assertDontSee('class="pdv-receipt-preview-shell"',false);

        $this->get(route('pdv.receipt',['sale'=>$sale,'embed'=>1]))
            ->assertOk()
            ->assertViewIs('pdv.receipt')
            ->assertSee('pdv-receipt-embedded')
            ->assertSee('SEM VALOR FISCAL')
            ->assertDontSee('Voltar ao PDV')
            ->assertDontSee('Imprimir comprovante interno')
            ->assertDontSee('let automatic=')
            ->assertDontSee('window.print()');

        self::assertStringContainsString('dialog.showModal()',
            file_get_contents(public_path('js/pdv-receipt-modal.js')));
        self::assertStringContainsString('frame.contentWindow.print()',
            file_get_contents(public_path('js/pdv-receipt-modal.js')));
        self::assertStringContainsString("left:-10000px!important",
            file_get_contents(public_path('css/pdv-lumeron.css')));
        self::assertSame(1,Sale::count());
    }

    public function test_sales_receipt_pdf_does_not_use_cash_values_from_previous_session(): void
    {
        $seller=$this->operator(['sales']);
        $sale=$this->sale($seller);
        $sale->update(['cash_received'=>'65.00','change_amount'=>'0.00']);
        PaymentMethod::firstOrCreate(['code'=>'cash'],[
            'name'=>'Dinheiro','kind'=>'cash','is_active'=>true,'pdv_enabled'=>true,
        ]);
        $sale->payments()->create([
            'installment'=>1,'amount'=>'65.00','payment_method'=>'cash',
            'receivable'=>false,
        ]);

        $this->actingAs($seller)->withSession([
            'pdv_cash_received'=>1000,
            'pdv_change'=>935,
        ]);
        $html=app(SaleReceiptPdfService::class)->renderHtml($sale);
        self::assertStringContainsString('65,00',$html);
        self::assertStringNotContainsString('1.000,00',$html);
        self::assertStringNotContainsString('935,00',$html);
    }

    public function test_fast_thermal_pdf_keeps_logo_and_one_page_per_copy_without_browser(): void
    {
        $seller=$this->operator(['sales']);
        $sale=$this->sale($seller);
        $this->actingAs($seller);
        \Illuminate\Support\Facades\Storage::fake('public');

        // PNG RGBA construído sem GD/Chrome: simula marca personalizada.
        $width=160;
        $height=70;
        $chunk=fn(string $type,string $body)=>
            pack('N',strlen($body)).$type.$body.pack('N',crc32($type.$body));
        $rgba=str_repeat("\x22\x99\xcc\xbb",$width);
        $rows=str_repeat("\x00".$rgba,$height);
        $logo="\x89PNG\r\n\x1a\n"
            .$chunk('IHDR',pack('NNCCCCC',$width,$height,8,6,0,0,0))
            .$chunk('IDAT',gzcompress($rows,6))
            .$chunk('IEND','');
        \Illuminate\Support\Facades\Storage::disk('public')
            ->put('receipts/company-logo.png',$logo);
        \App\Models\CompanySetting::current()->update([
            'logo_path'=>'receipts/company-logo.png',
            'print_header'=>'Cupom personalizado para conferencia de pagamento',
            'print_footer'=>'Agradecemos a preferencia. Guarde o comprovante.',
        ]);
        $sale->update(['notes'=>str_repeat('Observacao do atendimento no balcao. ',10)]);
        for ($i=0;$i<12;$i++) {
            $sale->items()->create([
                'item_type'=>'product',
                'product_name'=>'Produto longo para conferir a quebra do papel '.$i,
                'product_sku'=>'SKU-'.$i,
                'quantity'=>'1.000','unit_price'=>'2.00',
                'discount'=>'0.00','line_total'=>'2.00',
            ]);
        }

        foreach ([['80',1],['58',1],['58',2]] as [$paper,$copies]) {
            \App\Models\AppSetting::put('pdv','receipt_width',$paper);
            \App\Models\AppSetting::put('pdv','receipt_copies',$copies);
            $start=microtime(true);
            $pdf=app(SaleReceiptPdfService::class)->render($sale);
            self::assertLessThan(2.0,microtime(true)-$start,'Comprovante não deve esperar Chrome/Edge.');
            self::assertStringStartsWith('%PDF-',$pdf);
            self::assertStringContainsString('%%EOF',substr($pdf,-1024));
            self::assertStringContainsString('/Subtype /Image',$pdf,'Logo cadastrada preservada.');
            preg_match_all('/\/Type \/Page\b/',$pdf,$pages);
            self::assertCount($copies,$pages[0],$paper.'mm deve conter somente as vias configuradas.');
        }

        $service=file_get_contents(app_path('Services/SaleReceiptPdfService.php'));
        self::assertNotFalse($service);
        self::assertStringNotContainsString('proc_open(',$service);
        self::assertStringNotContainsString('--headless',$service);
        self::assertStringNotContainsString('usleep(',$service);
    }

    public function test_large_png_logo_does_not_block_other_laravel_pages(): void
    {
        $seller=$this->operator(['sales']);
        $sale=$this->sale($seller);
        $this->actingAs($seller);
        \Illuminate\Support\Facades\Storage::fake('public');

        // Imagem válida de 1024x768 pixels, comprimida, que antes seria
        // decodificada pixel por pixel no próprio worker PHP do Laragon.
        $width=1024;
        $height=768;
        $chunk=fn(string $type,string $body)=>
            pack('N',strlen($body)).$type.$body.pack('N',crc32($type.$body));
        $rgba=str_repeat("\x22\x99\xcc\xbb",$width);
        $raw=str_repeat("\x00".$rgba,$height);
        $logo="\x89PNG\r\n\x1a\n"
            .$chunk('IHDR',pack('NNCCCCC',$width,$height,8,6,0,0,0))
            .$chunk('IDAT',gzcompress($raw,6))
            .$chunk('IEND','');
        \Illuminate\Support\Facades\Storage::disk('public')->put('big-logo.png',$logo);
        \App\Models\CompanySetting::current()->update(['logo_path'=>'big-logo.png']);

        $start=microtime(true);
        $pdf=app(SaleReceiptPdfService::class)->render($sale);
        self::assertLessThan(2.0,microtime(true)-$start,
            'A logo não pode consumir o worker enquanto o usuário acessa o ERP.');
        self::assertStringStartsWith('%PDF-',$pdf);
        self::assertStringContainsString('%%EOF',substr($pdf,-1024));

        $this->get(route('sales.index'))->assertOk();
    }

    public function test_native_php_generator_produces_valid_pdf_without_chromium(): void
    {
        $seller=$this->operator(['sales']);
        $sale=$this->sale($seller);
        $this->actingAs($seller);
        $pdf=app(SaleReceiptPdfService::class)->render($sale);
        self::assertStringStartsWith('%PDF-',$pdf);
        self::assertStringContainsString('%%EOF',substr($pdf,-1024));
        self::assertGreaterThan(1000,strlen($pdf));

        if (is_file('/usr/bin/pdfinfo')) {
            $temporary=tempnam(sys_get_temp_dir(),'receipt-pdf-');
            try {
                file_put_contents($temporary,$pdf);
                $process=new \Symfony\Component\Process\Process(['pdfinfo',$temporary]);
                $process->run();
                self::assertTrue($process->isSuccessful(),$process->getErrorOutput());
                self::assertMatchesRegularExpression('/Pages:\s+1\b/',$process->getOutput());
            } finally {
                @unlink($temporary);
            }
        }
    }
}
