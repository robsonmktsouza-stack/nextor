<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\PdvCashRegister;
use App\Models\PdvCashSession;
use App\Models\User;
use App\Services\PdvCashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class PdvCashShiftSecurityTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\WithConfiguredCompany;

    private function operator(string $email='operator@example.test',string $role='operator'): User
    {
        return User::query()->create([
            'name'=>'Operador '.$email,
            'email'=>$email,
            'password'=>'SenhaTeste123',
            'role'=>$role,
            'permissions'=>['pdv'],
            'is_active'=>true,
        ]);
    }

    public function test_open_requires_operator_identity_and_physical_register(): void
    {
        $operator=$this->operator();
        $register=PdvCashRegister::query()->firstOrFail();
        $this->actingAs($operator)->post(route('pdv.cash.open'),[
            'register_id'=>$register->id,'opening_amount'=>'50.00',
        ])->assertSessionHasErrors('credential');
        self::assertSame(0,PdvCashSession::query()->count());

        $this->post(route('pdv.cash.open'),[
            'register_id'=>$register->id,'opening_amount'=>'50.00',
            'credential'=>'SenhaTeste123',
        ])->assertRedirect(route('pdv.index'));
        self::assertDatabaseHas('pdv_cash_sessions',[
            'user_id'=>$operator->id,'register_id'=>$register->id,
            'opening_amount'=>'50.00','closed_at'=>null,
        ]);
    }

    public function test_register_cannot_be_opened_by_two_operators_simultaneously(): void
    {
        $first=$this->operator();
        $second=$this->operator('other@example.test');
        $cash=app(PdvCashService::class);
        $register=PdvCashRegister::query()->firstOrFail();
        $cash->open($first->id,'80.00',null,$register->id);
        try {
            $cash->open($second->id,'70.00',null,$register->id);
            self::fail('Caixa físico aberto duas vezes.');
        } catch (\Illuminate\Validation\ValidationException $error) {
            self::assertArrayHasKey('register_id',$error->errors());
        }
        self::assertSame(1,PdvCashSession::query()->open()->count());
    }

    public function test_shift_handover_is_atomic_uses_two_credentials_and_logs_in_next_operator(): void
    {
        $first=$this->operator();
        $next=$this->operator('next@example.test');
        $cash=app(PdvCashService::class);
        $old=$cash->open($first->id,'120.00',null,PdvCashRegister::query()->firstOrFail()->id);
        $this->actingAs($first)->post(route('pdv.cash.handover'),[
            'incoming_user_id'=>$next->id,
            'outgoing_credential'=>'SenhaTeste123',
            'incoming_credential'=>'invalid-password',
            'counted_amount'=>'120.00',
        ])->assertSessionHasErrors('credential');
        self::assertNull($old->fresh()->closed_at);
        self::assertSame(1,PdvCashSession::query()->count());

        $this->post(route('pdv.cash.handover'),[
            'incoming_user_id'=>$next->id,
            'outgoing_credential'=>'SenhaTeste123',
            'incoming_credential'=>'SenhaTeste123',
            'counted_amount'=>'120.00',
        ])->assertRedirect(route('pdv.index'));
        $new=PdvCashSession::query()->where('user_id',$next->id)->firstOrFail();
        self::assertSame($old->id,$new->previous_session_id);
        self::assertSame($old->register_id,$new->register_id);
        self::assertSame('120.00',$new->opening_amount);
        self::assertSame($next->id,$old->fresh()->handover_to);
        self::assertNotNull($old->fresh()->closed_at);
        self::assertSame($next->id,Auth::id());
        self::assertSame(1,PdvCashSession::query()->open()->count());
    }

    public function test_handover_difference_requires_reason_and_does_not_partial_close(): void
    {
        $first=$this->operator();
        $second=$this->operator('second@example.test');
        $original=app(PdvCashService::class)->open($first->id,'100.00',null,PdvCashRegister::query()->firstOrFail()->id);
        $this->actingAs($first)->post(route('pdv.cash.handover'),[
            'incoming_user_id'=>$second->id,
            'outgoing_credential'=>'SenhaTeste123',
            'incoming_credential'=>'SenhaTeste123',
            'counted_amount'=>'95.00',
        ])->assertSessionHasErrors('notes');
        self::assertNull($original->fresh()->closed_at);
        self::assertSame(1,PdvCashSession::query()->count());
    }

    public function test_pin_requires_current_password_and_allows_cash_close(): void
    {
        $operator=$this->operator();
        $this->actingAs($operator)->post(route('pdv.cash.pin'),[
            'current_password'=>'SenhaTeste123',
            'pin'=>'830527','pin_confirmation'=>'830527',
        ])->assertRedirect(route('pdv.index'));
        self::assertTrue(Hash::check('830527',$operator->fresh()->pdv_pin_hash));

        $register=PdvCashRegister::query()->firstOrFail();
        $this->post(route('pdv.cash.open'),[
            'register_id'=>$register->id,'opening_amount'=>'19.00',
            'credential'=>'830527',
        ])->assertRedirect(route('pdv.index'));
        $this->get(route('pdv.index'))->assertOk()
            ->assertSee('Fechamento cego')
            ->assertDontSee('Saldo esperado (conferência)');
        $this->post(route('pdv.cash.close'),[
            'closing_amount'=>'19.00','credential'=>'830527',
        ])->assertRedirect();
        self::assertSame(0,PdvCashSession::query()->open()->count());
    }

    public function test_supervisor_authorization_is_required_and_audited_for_high_value_cash_exit(): void
    {
        $operator=$this->operator();
        $supervisor=$this->operator('manager@example.test','manager');
        $cash=app(PdvCashService::class);
        $cash->open($operator->id,'500.00',null);
        $this->actingAs($operator)->post(route('pdv.cash.movement'),[
            'type'=>'withdrawal','amount'=>'250.00','reason'=>'Depósito no cofre',
        ])->assertSessionHasErrors('supervisor_user_id');

        $this->post(route('pdv.cash.movement'),[
            'type'=>'withdrawal','amount'=>'250.00','reason'=>'Depósito no cofre',
            'supervisor_user_id'=>$supervisor->id,
            'supervisor_credential'=>'SenhaTeste123',
        ])->assertRedirect(route('pdv.index'));
        self::assertDatabaseHas('pdv_cash_movements',[
            'user_id'=>$operator->id,'approved_by'=>$supervisor->id,
            'type'=>'withdrawal','amount'=>'250.00',
        ]);
    }

    public function test_terminal_administration_requires_admin_and_refuses_disabling_open_till(): void
    {
        $admin=$this->operator('owner@example.test','admin');
        $this->actingAs($admin)->post(route('pdv.cash.registers.store'),[
            'code'=>'CX02','name'=>'Caixa 02','credential'=>'SenhaTeste123',
        ])->assertRedirect();
        $register=PdvCashRegister::query()->where('code','CX02')->firstOrFail();
        app(PdvCashService::class)->open($admin->id,'15.00',null,$register->id);
        $this->put(route('pdv.cash.registers.update',$register),[
            'name'=>'Caixa 02','is_active'=>'0','credential'=>'SenhaTeste123',
        ])->assertSessionHasErrors('is_active');
        self::assertTrue($register->fresh()->is_active);
    }

    public function test_closure_review_requires_independent_admin_when_available(): void
    {
        $owner=$this->operator('owner@example.test','admin');
        $reviewer=$this->operator('reviewer@example.test','admin');
        $cash=app(PdvCashService::class);
        $open=$cash->open($owner->id,'10.00',null);
        $cash->close($owner->id,'10.00',null);
        $this->actingAs($owner)->post(route('pdv.cash.review',$open),[
            'review_notes'=>'Conferência do caixa',
            'credential'=>'SenhaTeste123',
        ])->assertSessionHasErrors('review_notes');
        $this->actingAs($reviewer)->post(route('pdv.cash.review',$open),[
            'review_notes'=>'Conferência independente aprovada',
            'credential'=>'SenhaTeste123',
        ])->assertRedirect();
        self::assertSame($reviewer->id,$open->fresh()->reviewed_by);
    }
}
