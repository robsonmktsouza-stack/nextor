<?php

namespace Tests\Feature;

use App\Models\PdvCashRegister;
use App\Models\PdvCashSession;
use App\Models\User;
use App\Services\PdvCashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithConfiguredCompany;
use Tests\TestCase;

final class PdvCashReportModalTest extends TestCase
{
    use RefreshDatabase;
    use WithConfiguredCompany;

    private function operator(): User
    {
        return User::query()->create([
            'name'=>'Operador PDV',
            'email'=>'pdv-report-modal@example.test',
            'password'=>'SenhaTeste123',
            'role'=>'admin',
            'permissions'=>['pdv'],
            'is_active'=>true,
        ]);
    }

    private function openedShift(User $operator): PdvCashSession
    {
        return app(PdvCashService::class)->open(
            $operator->id,'50.00',null,
            PdvCashRegister::query()->firstOrFail()->id,
        );
    }

    public function test_normal_cash_close_returns_to_pdv_and_opens_receipt_in_modal(): void
    {
        $operator=$this->operator();
        $shift=$this->openedShift($operator);
        $this->actingAs($operator)->post(route('pdv.cash.close'),[
            'closing_amount'=>'50.00',
            'credential'=>'SenhaTeste123',
        ])->assertRedirect(route('pdv.index'))
          ->assertSessionHas('pdv_cash_report_id',$shift->id);

        self::assertNotNull($shift->fresh()->closed_at);
        $reportUrl=route('pdv.cash.report',['cashSession'=>$shift,'embed'=>1]);
        $this->get(route('pdv.index'))->assertOk()
            ->assertSee('id="pdvCashReportModal"',false)
            ->assertSee('data-auto-report-url="'.$reportUrl.'"',false)
            ->assertSee('Voltar às vendas')
            ->assertSee('Imprimir / Salvar PDF')
            ->assertSee('pdv-cash-report-modal.js');
    }

    public function test_embedded_report_keeps_numbers_and_hides_standalone_navigation(): void
    {
        $operator=$this->operator();
        $shift=$this->openedShift($operator);
        app(PdvCashService::class)->close($operator->id,'50.00',null);
        $this->actingAs($operator);
        $this->get(route('pdv.cash.report',['cashSession'=>$shift,'embed'=>1]))
            ->assertOk()
            ->assertSee('Fechamento de caixa #'.$shift->id)
            ->assertSee('Dinheiro contado')
            ->assertDontSee('<nav>',false)
            ->assertSee('name="review_notes"',false);

        // The same URL without embed remains usable as a direct print page.
        $this->get(route('pdv.cash.report',$shift))
            ->assertOk()
            ->assertSee('<nav>',false)
            ->assertSee('Imprimir / Salvar PDF');
    }

    public function test_manager_review_preserves_report_inside_embedded_frame(): void
    {
        $operator=$this->operator();
        $shift=$this->openedShift($operator);
        app(PdvCashService::class)->close($operator->id,'50.00',null);
        $this->actingAs($operator)->post(
            route('pdv.cash.review',['cashSession'=>$shift,'embed'=>1]),
            ['review_notes'=>'Caixa conferido e aprovado','credential'=>'SenhaTeste123'],
        )->assertRedirect(route('pdv.cash.report',['cashSession'=>$shift,'embed'=>1]));
        self::assertNotNull($shift->fresh()->reviewed_at);
    }

    public function test_history_links_open_modal_without_leaving_the_listing(): void
    {
        $operator=$this->operator();
        $shift=$this->openedShift($operator);
        app(PdvCashService::class)->close($operator->id,'50.00',null);
        $this->actingAs($operator)->get(route('pdv.cash.history'))->assertOk()
            ->assertSee('data-cash-report-open',false)
            ->assertSee('data-cash-report-url="'.route('pdv.cash.report',[
                'cashSession'=>$shift,'embed'=>1,
            ]).'"',false)
            ->assertSee('Voltar ao histórico')
            ->assertSee('pdv-cash-report-modal.js');
    }

    public function test_report_modal_is_lazy_and_prints_only_its_iframe(): void
    {
        $js=file_get_contents(public_path('js/pdv-cash-report-modal.js'));
        self::assertStringContainsString('frame.src=safeUrl',$js);
        self::assertStringContainsString('frame.contentWindow.print()',$js);
        self::assertStringContainsString('dialog.showModal()',$js);
        self::assertStringContainsString('frame.removeAttribute(',$js);
        self::assertStringContainsString('if(target.origin!==window.location.origin',$js);
    }
}
