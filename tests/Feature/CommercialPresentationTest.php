<?php

namespace Tests\Feature;

use App\Models\FiscalDocumentJob;
use App\Models\PdvCashMovement;
use App\Models\PdvCashSession;
use App\Models\Sale;
use App\Models\User;
use App\Services\QuotePdfService;
use App\Services\SaleReceiptPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class CommercialPresentationTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\WithConfiguredCompany;

    private function admin(): User
    {
        return User::query()->firstOrCreate(['email'=>'commercial-presentation@example.test'], [
            'name'=>'Responsável comercial', 'password'=>'senhaSegura123',
            'role'=>'admin', 'is_active'=>true,
        ]);
    }

    private function sale(string $type = 'sale', string $source = 'manual'): Sale
    {
        return Sale::query()->create([
            'user_id'=>$this->admin()->id, 'operation_type'=>$type, 'source'=>$source,
            'operation_date'=>today(), 'status'=>'completed', 'subtotal'=>'20.00',
            'discount_total'=>0, 'total'=>'20.00', 'completed_at'=>now(),
        ]);
    }

    #[DataProvider('pdfRoutes')]
    public function test_pdf_failures_keep_the_cause_out_of_the_http_response(string $route, string $renderer, string $type, string $source): void
    {
        $sale=$this->sale($type,$source);
        $mock=\Mockery::mock($renderer);
        $mock->shouldReceive('render')->once()->andThrow(new RuntimeException(
            'proc_open(): Chrome /private/runtime/quote-123.html SQLSTATE[HY000] password=secret-example'
        ));
        $this->app->instance($renderer,$mock);

        $response=$this->actingAs($this->admin())->getJson(route($route, ['sale'=>$sale,'print'=>0]));
        $response->assertStatus(503)->assertDontSee('proc_open')->assertDontSee('SQLSTATE')
            ->assertDontSee('/private/runtime')->assertDontSee('secret-example');
        self::assertStringContainsString('Não foi possível gerar', $response->json('message'));
        self::assertSame('completed',$sale->fresh()->status);
    }

    public static function pdfRoutes(): array
    {
        return [
            ['sales.receipt',SaleReceiptPdfService::class,'sale','manual'],
            ['sales.quote.pdf',QuotePdfService::class,'quote','manual'],
            ['pdv.receipt',SaleReceiptPdfService::class,'sale','pdv'],
        ];
    }

    public function test_business_error_remains_actionable_instead_of_being_replaced_by_a_generic_pdf_error(): void
    {
        $sale=$this->sale('quote');
        $mock=\Mockery::mock(QuotePdfService::class);
        $mock->shouldReceive('render')->once()->andThrow(new RuntimeException('Este documento não é um orçamento.'));
        $this->app->instance(QuotePdfService::class,$mock);
        $this->actingAs($this->admin())->getJson(route('sales.quote.pdf',$sale))
            ->assertStatus(503)->assertJsonPath('message','Este documento não é um orçamento.');
    }

    public function test_pdv_receipt_translates_pending_fiscal_state_without_claiming_authorization(): void
    {
        $sale=$this->sale('sale','pdv');
        $note=FiscalDocumentJob::query()->create([
            'sale_id'=>$sale->id,'document_type'=>'nfce','status'=>'pending',
            'environment'=>'homologation','emission_mode'=>'normal','series'=>1,'document_number'=>35,
        ]);
        $this->actingAs($this->admin())->get(route('pdv.receipt',['sale'=>$sale,'print'=>0,'status'=>1]))
            ->assertOk()->assertSee('aguardando confirmação')
            ->assertDontSee(': pending')->assertDontSee('NFC-e autorizado');
        self::assertSame('pending',$note->fresh()->status);
        self::assertSame(1,Sale::query()->count());
    }

    public function test_legacy_payment_codes_are_not_printed_on_sales_or_customer_quotes(): void
    {
        $this->actingAs($this->admin());
        foreach (['sale','quote'] as $type) {
            $sale=$this->sale($type);
            $sale->payments()->create([
                'installment'=>1,'amount'=>'20.00','payment_method'=>'legacy_wire_internal',
                'receivable'=>false,
            ]);
            $response=$this->get(route('sales.show',$sale))->assertOk();
            $response->assertSee('Forma de pagamento não cadastrada')->assertDontSee('legacy_wire_internal');
            if ($type==='quote') {
                $html=app(QuotePdfService::class)->renderHtml($sale);
                self::assertStringNotContainsString('legacy_wire_internal',$html);
                self::assertStringContainsString('Forma de pagamento a confirmar',$html);
            }
        }
    }

    public function test_cash_report_keeps_amounts_but_not_unknown_internal_movement_codes(): void
    {
        $this->actingAs($this->admin());
        $session=new PdvCashSession([
            'user_id'=>$this->admin()->id,'opened_at'=>now(),'closed_at'=>now(),
            'closing_amount'=>'12.00','difference_amount'=>0,
        ]);
        $session->id=6;
        $movement=new PdvCashMovement(['type'=>'internal_legacy_adjustment','amount'=>'12.00','reason'=>'Conferência do turno']);
        $movement->setRelation('user',$this->admin());
        $movement->created_at=now();
        $session->setRelation('user',$this->admin());
        $session->setRelation('register',null);
        $session->setRelation('movements',collect([$movement]));
        $html=view('pdv.cash-report',[
            'session'=>$session,'methods'=>collect(),
            'breakdown'=>['payments'=>['internal_legacy_payment'=>1200],'expected'=>1200],
        ])->render();
        self::assertStringContainsString('Outra movimentação',$html);
        self::assertStringContainsString('Forma de pagamento não cadastrada',$html);
        self::assertStringContainsString('12,00',$html);
        self::assertStringNotContainsString('internal_legacy_adjustment',$html);
        self::assertStringNotContainsString('internal_legacy_payment',$html);
    }
}
