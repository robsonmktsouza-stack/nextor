<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\CompanySetting;
use App\Models\FinancialEntry;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\QuotePdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class QuoteCommercialDocumentTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\WithConfiguredCompany;

    private function admin(): User
    {
        return User::firstOrCreate(['email'=>'quote-tests@example.com'],[
            'name'=>'Comercial Teste',
            'password'=>'senhaSegura123','role'=>'admin','is_active'=>true,
        ]);
    }

    private function product(int $stock=8): Product
    {
        return Product::create([
            'sku'=>'COT-10','name'=>'Serviço de instalação e material',
            'unit'=>'UN','sale_price'=>'49.50','cost_price'=>'10',
            'stock_quantity'=>$stock.'.000','control_stock'=>true,
            'is_active'=>true,
        ]);
    }

    private function createQuote(Product $product): Sale
    {
        $this->actingAs($this->admin())
            ->post(route('sales.store'),[
                'operation_type'=>'quote',
                'operation_date'=>now()->toDateString(),
                'quote_expires_at'=>now()->addDays(10)->toDateString(),
                'notes'=>'Entrega a combinar com o cliente.',
                'final_consumer'=>1,
                'items'=>[[
                    'item_type'=>'product','product_id'=>$product->id,
                    'quantity'=>'2','unit_price'=>'49.50','discount'=>'5.00',
                    'notes'=>'Conforme proposta aprovada',
                ]],
            ])->assertSessionHasNoErrors();
        return Sale::where('operation_type','quote')->firstOrFail();
    }

    public function test_quote_has_customer_ready_branded_html_and_does_not_affect_stock_or_finance(): void
    {
        $product=$this->product();
        $quote=$this->createQuote($product);
        CompanySetting::current()->update([
            'trade_name'=>'Oficina Exemplo',
            'legal_name'=>'OFICINA EXEMPLO LTDA',
            'phone'=>'(11) 3333-4444',
            'email'=>'comercial@example.test',
        ]);

        self::assertEquals('8.000',$product->fresh()->stock_quantity);
        self::assertSame(0,StockMovement::count());
        self::assertSame(0,FinancialEntry::count());
        self::assertSame('94.00',$quote->total);
        self::assertSame('completed',$quote->status);
        self::assertSame(now()->addDays(10)->toDateString(),$quote->quote_expires_at->toDateString());

        $html=app(QuotePdfService::class)->renderHtml($quote);
        foreach (['Oficina Exemplo','OFICINA EXEMPLO LTDA',
            'Serviço de instalação e material','COT-10','R$ 94,00',
            'Entrega a combinar com o cliente.','não é documento fiscal'] as $value) {
            self::assertStringContainsString($value,$html);
        }
        self::assertStringNotContainsString('images/lumeron/logo.png',$html);
    }

    public function test_quote_pdf_matches_user_excel_a4_print_model_without_loading_erp_css(): void
    {
        $quote=$this->createQuote($this->product());
        $html=app(QuotePdfService::class)->renderHtml($quote);

        foreach ([
            '@page { size:A4; margin:20mm 3mm 14mm; }',
            'background:#477b78',
            'background:#e9eff0',
            '<div class="accent-band"></div>',
            'PROPOSTA DE FORNECIMENTO | PRODUTOS E SERVIÇOS',
            'Dados do cliente',
            'Cliente / Razão social',
            'Validade da proposta',
            'Discriminação dos itens',
            '<table class="items">',
            '<th>Tipo</th><th>Código</th><th>Descrição do produto / serviço</th>',
            '<th class="center">Un.</th>',
            'Condições comerciais',
            'Resumo financeiro',
            '<table class="totals">',
            'Observações / informações adicionais',
            'Responsável pela proposta',
            'Aceite do cliente',
            'TOTAL GERAL',
            'Orçamento comercial — não é documento fiscal e não comprova pagamento.',
        ] as $expected) {
            self::assertStringContainsString($expected,$html);
        }
        self::assertStringNotContainsString('images/lumeron/logo.png',$html);
        self::assertStringNotContainsString('css/erp.css',$html);
        self::assertStringNotContainsString('border-radius:', $html);
        self::assertStringNotContainsString('Proposta comercial</h1>',$html);
        self::assertStringNotContainsString('background:#294e68',$html);
    }

    public function test_commercial_quote_with_products_and_services_shows_correct_breakdown_without_changing_totals(): void
    {
        $quote=$this->createQuote($this->product());
        $quote->items()->create([
            'item_type'=>'service','product_id'=>null,'service_id'=>null,
            'product_name'=>'Instalação de acessórios','product_sku'=>'',
            'quantity'=>'1.000','unit_price'=>'50.00','discount'=>'0.00',
            'line_total'=>'50.00','notes'=>null,
        ]);
        $quote->update([
            'subtotal'=>'149.00','discount_total'=>'5.00','total'=>'144.00',
        ]);

        $html=app(QuotePdfService::class)->renderHtml($quote);
        foreach ([
            'background:#477b78',
            'PROPOSTA DE FORNECIMENTO | PRODUTOS E SERVIÇOS',
            'Serviço',
            'Instalação de acessórios',
            'R$ 99,00',
            'R$ 50,00',
            'R$ 5,00',
            'R$ 144,00',
            'TOTAL GERAL',
        ] as $expected) {
            self::assertStringContainsString($expected,$html);
        }
        self::assertStringContainsString('Produtos',$html);
        self::assertStringContainsString('Serviços',$html);
        self::assertStringContainsString('R$ 5,00',$html);
        self::assertSame('144.00',(string)$quote->fresh()->total);
        self::assertSame(0,FinancialEntry::count());
    }

    public function test_quote_pdf_uses_saved_payment_label_and_actual_validity_length(): void
    {
        $quote=$this->createQuote($this->product());
        \App\Models\PaymentMethod::query()->firstOrCreate(['code'=>'pix'],[
            'name'=>'PIX','kind'=>'pix','sort_order'=>10,
            'is_active'=>true,'pdv_enabled'=>true,
        ]);
        $quote->payments()->create([
            'installment'=>1,
            'amount'=>'94.00',
            'payment_method'=>'pix',
            'receivable'=>false,
        ]);

        $html=app(QuotePdfService::class)->renderHtml($quote);
        self::assertStringContainsString('Pagamento: PIX.', $html);
        self::assertStringContainsString('(10 dias)', $html);
        self::assertStringContainsString('Forma de pagamento', $html);
        self::assertStringContainsString('Prazo de entrega / execução: a combinar.', $html);
        self::assertSame('94.00',(string)$quote->fresh()->total);
        self::assertSame(0,FinancialEntry::count());
    }

    public function test_company_logo_is_embedded_without_exposing_a_public_attachment_url(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/rX0AAAAASUVORK5CYII=');
        \Illuminate\Support\Facades\Storage::disk('public')->put('company/logo.png',$png);
        CompanySetting::current()->update(['logo_path'=>'company/logo.png']);
        $quote=$this->createQuote($this->product());
        $html=app(QuotePdfService::class)->renderHtml($quote);

        self::assertStringContainsString('data:image/png;base64,',$html);
        self::assertStringNotContainsString('http://127.0.0.1:8000/company/logo.png',$html);
    }

    public function test_real_chromium_generates_valid_pdf_when_installed(): void
    {
        if (!is_file('/usr/bin/google-chrome') && !is_file('/usr/bin/chromium')
            && !is_file('/usr/bin/chromium-browser')) {
            $this->markTestSkipped('Chrome/Chromium não instalado no runner Linux.');
        }
        $quote=$this->createQuote($this->product());
        $pdf=app(QuotePdfService::class)->render($quote);
        self::assertStringStartsWith('%PDF-',$pdf);
        self::assertStringContainsString('%%EOF',substr($pdf,-1024));
        self::assertGreaterThan(1500,strlen($pdf));
        $this->assertSinglePrintPage($pdf);

        // O modelo de referência enviado pelo usuário tem nove itens numa
        // única página A4; teste real de paginação, não apenas HTML válido.
        $original=$quote->items()->firstOrFail();
        for($i=2;$i<=9;$i++) {
            $quote->items()->create([
                'item_type'=>'product','product_id'=>$original->product_id,'service_id'=>null,
                'product_name'=>'FILTRO DE AR MOTOR E SISTEMA DE REFRIGERAÇÃO DO VEÍCULO',
                'product_sku'=>'FIL-'.str_pad((string)$i,3,'0',STR_PAD_LEFT),
                'quantity'=>'1.000','unit_price'=>'10.00',
                'discount'=>'0.00','line_total'=>'10.00','notes'=>null,
            ]);
        }
        $quote->update(['subtotal'=>'179.00','discount_total'=>'5.00','total'=>'174.00']);
        $nineItemPdf=app(QuotePdfService::class)->render($quote->fresh());
        self::assertStringStartsWith('%PDF-',$nineItemPdf);
        $this->assertSinglePrintPage($nineItemPdf);
    }

    private function assertSinglePrintPage(string $pdf): void
    {
        if (!is_file('/usr/bin/pdfinfo')) {
            // Ambiente Windows não precisa de poppler para executar o teste
            // funcional; no runner Linux validamos a paginação de verdade.
            return;
        }
        $temporary=tempnam(sys_get_temp_dir(),'quote-a4-');
        self::assertNotFalse($temporary);
        try {
            file_put_contents($temporary,$pdf);
            $process=new \Symfony\Component\Process\Process(['pdfinfo',$temporary]);
            $process->run();
            self::assertTrue($process->isSuccessful(),$process->getErrorOutput());
            self::assertMatchesRegularExpression('/^Pages:\\s+1\\s*$/m',$process->getOutput(),
                'A proposta de até nove itens deve ocupar exatamente uma folha A4.');
        } finally {
            @unlink($temporary);
        }
    }

    public function test_pdf_is_restricted_to_authenticated_sales_and_quote_documents(): void
    {
        $quote=$this->createQuote($this->product());
        $this->app->instance(QuotePdfService::class,
            \Mockery::mock(QuotePdfService::class)->shouldReceive('render')
                ->twice()->andReturn("%PDF-1.4\nquote test\n%%EOF")->getMock()
        );

        auth()->logout();
        $this->get(route('sales.quote.pdf',$quote))->assertRedirect('/login');

        $preview=$this->actingAs($this->admin())
            ->get(route('sales.quote.pdf',$quote));
        $preview->assertOk()
            ->assertHeader('Content-Type','application/pdf')
            ->assertHeader('Content-Disposition','inline; filename="Orcamento-'.str_pad((string)$quote->id,5,'0',STR_PAD_LEFT).'.pdf"');
        self::assertStringContainsString('no-store',(string)$preview->headers->get('Cache-Control'));

        $this->get(route('sales.quote.pdf',['sale'=>$quote,'download'=>1]))
            ->assertOk()
            ->assertHeader('Content-Disposition','attachment; filename="Orcamento-'.str_pad((string)$quote->id,5,'0',STR_PAD_LEFT).'.pdf"');
    }

    public function test_conversion_runs_once_with_original_totals_and_real_stock_movement(): void
    {
        AppSetting::put('operations','auto_finance_sale',true);
        $product=$this->product();
        $quote=$this->createQuote($product);
        $this->actingAs($this->admin())
            ->post(route('sales.quote.convert',$quote))
            ->assertRedirect();

        $quote->refresh();
        self::assertSame('converted',$quote->status);
        $sale=Sale::where('source_quote_id',$quote->id)->firstOrFail();
        self::assertSame('sale',$sale->operation_type);
        self::assertSame('94.00',$sale->total);
        self::assertSame('49.50',$sale->items->first()->unit_price);
        self::assertSame('5.00',$sale->items->first()->discount);
        self::assertEquals('6.000',$product->fresh()->stock_quantity);
        self::assertSame(1,StockMovement::where('type','sale')->count());
        $financialBefore=FinancialEntry::count();

        $this->post(route('sales.quote.convert',$quote))->assertSessionHasErrors('sale');
        self::assertSame(1,Sale::where('source_quote_id',$quote->id)->count());
        self::assertSame(1,StockMovement::where('type','sale')->count());
        self::assertSame($financialBefore,FinancialEntry::count());

        $this->get(route('sales.show',$quote))->assertOk()->assertSee('Venda gerada');
        $this->get(route('sales.show',$sale))->assertOk()->assertSee('Originada do orçamento');
    }

    public function test_expired_and_cancelled_quotes_cannot_convert(): void
    {
        $product=$this->product();
        $quote=$this->createQuote($product);
        $quote->update(['quote_expires_at'=>now()->subDay()->toDateString()]);
        $this->actingAs($this->admin())
            ->post(route('sales.quote.convert',$quote))->assertSessionHasErrors('sale');
        $quote->update(['quote_expires_at'=>now()->addDay()->toDateString(),'status'=>'cancelled']);
        $this->post(route('sales.quote.convert',$quote))->assertSessionHasErrors('sale');
        self::assertSame(0,Sale::where('operation_type','sale')->count());
        self::assertEquals('8.000',$product->fresh()->stock_quantity);
    }

    public function test_pdf_route_rejects_normal_sale_even_for_logged_in_operator(): void
    {
        $product=$this->product();
        $quote=$this->createQuote($product);
        $this->actingAs($this->admin())->post(route('sales.quote.convert',$quote));
        $sale=Sale::where('source_quote_id',$quote->id)->firstOrFail();
        $this->get(route('sales.quote.pdf',$sale))->assertNotFound();
    }

    public function test_quote_editor_shows_pdf_action_only_in_quote_mode(): void
    {
        $this->actingAs($this->admin())
            ->get(route('sales.create'))
            ->assertOk()
            ->assertSee('Salvar e visualizar PDF')
            ->assertSee('Validade do orçamento')
            ->assertSee('id="saleQuoteValidity"',false);
    }
}
