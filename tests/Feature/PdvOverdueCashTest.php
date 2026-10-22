<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\PdvCashRegister;
use App\Models\PdvCashSession;
use App\Models\User;
use App\Services\PdvCashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\WithConfiguredCompany;
use Tests\TestCase;

final class PdvOverdueCashTest extends TestCase
{
    use RefreshDatabase;
    use WithConfiguredCompany;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function cashier(string $email='maria@example.test',string $role='operator'): User
    {
        return User::query()->create([
            'name'=>explode('@',$email)[0],
            'email'=>$email,
            'password'=>'SenhaTeste123',
            'role'=>$role,
            'permissions'=>['pdv'],
            'is_active'=>true,
        ]);
    }

    private function yesterdayShift(User $owner): PdvCashSession
    {
        Carbon::setTestNow('2026-10-22 08:00:00');
        $register=PdvCashRegister::query()->firstOrFail();
        $shift=app(PdvCashService::class)->open($owner->id,'150.00',null,$register->id);
        Carbon::setTestNow('2026-10-23 08:00:00');
        return $shift;
    }

    public function test_overnight_shift_is_not_auto_closed_and_blocks_next_day_sales(): void
    {
        $maria=$this->cashier();
        $shift=$this->yesterdayShift($maria);
        $cash=app(PdvCashService::class);

        self::assertTrue($cash->isOverdue($shift->fresh()));
        self::assertNull($shift->fresh()->closed_at);
        try {
            $cash->forSale($maria->id);
            self::fail('Venda não pode ser finalizada em turno de ontem.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('cash_session',$e->errors());
        }
        self::assertNull($shift->fresh()->closed_at);
        $this->actingAs($maria)->get(route('pdv.index'))
            ->assertOk()
            ->assertSee('id="pdvOverdueCashDialog"',false)
            ->assertSee('data-must-resolve="1"',false)
            ->assertSee('22/10/2026')
            ->assertSee('Conferir e fechar meu caixa')
            ->assertDontSee('value="150.00"',false);
    }

    public function test_original_cashier_blind_closes_previous_day_and_opens_new_shift(): void
    {
        $maria=$this->cashier();
        $shift=$this->yesterdayShift($maria);
        $this->actingAs($maria)->post(route('pdv.cash.close'),[
            'closing_amount'=>'150.00','credential'=>'SenhaTeste123',
        ])->assertRedirect(route('pdv.cash.report',$shift));

        $shift->refresh();
        self::assertNotNull($shift->closed_at);
        self::assertSame($maria->id,$shift->closed_by);
        self::assertSame('0.00',$shift->difference_amount);

        $this->post(route('pdv.cash.open'),[
            'register_id'=>$shift->register_id,
            'opening_amount'=>'120.00','credential'=>'SenhaTeste123',
        ])->assertRedirect(route('pdv.index'));
        self::assertSame(1,PdvCashSession::query()->open()->count());
        self::assertSame('2026-10-23',app(PdvCashService::class)
            ->current($maria->id)->opened_at->toDateString());
    }

    public function test_abandoned_register_prevents_bypass_with_optional_opening(): void
    {
        $maria=$this->cashier();
        $joao=$this->cashier('joao@example.test');
        $shift=$this->yesterdayShift($maria);
        AppSetting::put('pdv','require_cash_opening',false);

        try {
            app(PdvCashService::class)->forSale($joao->id);
            self::fail('Venda sem turno não pode ignorar caixa abandonado.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('cash_session',$e->errors());
        }

        $this->actingAs($joao)->get(route('pdv.index'))
            ->assertOk()
            ->assertSee('data-must-resolve="1"',false)
            ->assertSee('Peça ao responsável pelo turno');

        $this->post(route('pdv.cash.overdue.close'),[
            'cash_session_id'=>$shift->id,'counted_amount'=>'150.00',
            'reason'=>'Conferência do caixa antigo','credential'=>'SenhaTeste123',
        ])->assertForbidden();
        self::assertNull($shift->fresh()->closed_at);
    }

    public function test_supervisor_must_reauthenticate_and_records_who_closed_absent_cashier_shift(): void
    {
        $maria=$this->cashier();
        $manager=$this->cashier('gerente@example.test','manager');
        $shift=$this->yesterdayShift($maria);

        $payload=[
            'cash_session_id'=>$shift->id,
            'counted_amount'=>'145.00',
            'reason'=>'Operadora ausente na conferência de abertura',
            'credential'=>'incorreta',
        ];
        $this->actingAs($manager)->post(route('pdv.cash.overdue.close'),$payload)
            ->assertSessionHasErrors('credential');
        self::assertNull($shift->fresh()->closed_at);

        $this->get(route('pdv.index'))->assertOk()
            ->assertSee('Regularização pelo responsável')
            ->assertSee(route('pdv.cash.overdue.close'),false);
        $payload['credential']='SenhaTeste123';
        $this->post(route('pdv.cash.overdue.close'),$payload)
            ->assertRedirect(route('pdv.cash.report',$shift));

        $shift->refresh();
        self::assertSame($maria->id,$shift->user_id);
        self::assertSame($manager->id,$shift->closed_by);
        self::assertSame('145.00',$shift->closing_amount);
        self::assertSame('-5.00',$shift->difference_amount);
        self::assertStringContainsString('Regularização supervisionada:',$shift->closing_notes);
        self::assertStringContainsString('Operadora ausente',$shift->closing_notes);
        self::assertNotNull($shift->closed_at);
        self::assertNull($shift->reviewed_at);

        app(PdvCashService::class)->open($manager->id,'145.00',null,$shift->register_id);
        self::assertSame(1,PdvCashSession::query()->open()->count());
    }

    public function test_manager_cannot_regularize_todays_shift_or_own_shift_or_twice(): void
    {
        $maria=$this->cashier();
        $manager=$this->cashier('gerente@example.test','manager');
        Carbon::setTestNow('2026-10-22 08:00:00');
        $cash=app(PdvCashService::class);
        $today=$cash->open($maria->id,'30.00',null);
        $this->actingAs($manager);
        $payload=[
            'cash_session_id'=>$today->id,
            'counted_amount'=>'30.00',
            'reason'=>'Fechamento de turno não concluído',
            'credential'=>'SenhaTeste123',
        ];
        $this->post(route('pdv.cash.overdue.close'),$payload)
            ->assertSessionHasErrors('cash_session');

        Carbon::setTestNow('2026-10-23 08:00:00');
        $payload['cash_session_id']=$today->id;
        $this->post(route('pdv.cash.overdue.close'),$payload)
            ->assertRedirect(route('pdv.cash.report',$today));
        $this->post(route('pdv.cash.overdue.close'),$payload)
            ->assertSessionHasErrors('cash_session');

        Carbon::setTestNow('2026-10-23 10:00:00');
        $own=$cash->open($manager->id,'20.00',null);
        Carbon::setTestNow('2026-10-24 09:00:00');
        $payload['cash_session_id']=$own->id;
        $this->post(route('pdv.cash.overdue.close'),$payload)
            ->assertSessionHasErrors('cash_session');
        self::assertNull($own->fresh()->closed_at);
    }

    public function test_overdue_shift_blocks_cash_movements_and_handover_not_just_checkout(): void
    {
        $maria=$this->cashier();
        $joao=$this->cashier('joao@example.test');
        $shift=$this->yesterdayShift($maria);
        try {
            app(PdvCashService::class)->movement($maria->id,'supply','10.00','Reposição');
            self::fail('Movimento em turno encerrado por data.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('cash_session',$e->errors());
        }
        try {
            app(PdvCashService::class)->handover($maria->id,$joao->id,'150.00',null);
            self::fail('Passagem não pode pular conferência de dia anterior.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('cash_session',$e->errors());
        }
        self::assertNull($shift->fresh()->closed_at);
        self::assertSame(1,PdvCashSession::query()->count());
    }

    public function test_healthy_register_can_keep_selling_while_another_has_overnight_pending(): void
    {
        $maria=$this->cashier();
        $joao=$this->cashier('joao@example.test');
        $this->yesterdayShift($maria);
        $other=PdvCashRegister::query()->create([
            'code'=>'CX02','name'=>'Caixa 02','is_active'=>true,
        ]);
        $shift=app(PdvCashService::class)->open($joao->id,'50.00',null,$other->id);
        self::assertSame($shift->id,app(PdvCashService::class)->forSale($joao->id)?->id);
        $this->actingAs($joao)->get(route('pdv.index'))->assertOk()
            ->assertSee('data-must-resolve="0"',false);
    }

    public function test_modal_and_server_stay_locked_when_user_reloads_next_day(): void
    {
        $maria=$this->cashier();
        $this->yesterdayShift($maria);
        $this->actingAs($maria)->get(route('pdv.index'))->assertOk()
            ->assertSee('id="pdvOverdueCashDialog"',false)
            ->assertSee('data-must-resolve="1"',false);
        $js=file_get_contents(public_path('js/pdv.js'));
        self::assertStringContainsString("overdueCashDialog?.addEventListener('cancel'",$js);
        self::assertStringContainsString('if(overdueCashRequired)event.preventDefault()',$js);
        self::assertStringContainsString('overdueCashDialog.showModal()',$js);
    }
}
