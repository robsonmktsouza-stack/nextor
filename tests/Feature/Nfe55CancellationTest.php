<?php

namespace Tests\Feature;

use App\Jobs\ProcessNfe55CancellationJob;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\NfeDraft;
use App\Models\OperationNature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\WithConfiguredCompany;
use Tests\TestCase;

final class Nfe55CancellationTest extends TestCase
{
    use RefreshDatabase,WithConfiguredCompany;

    private function draft(string $status='authorized',string $environment='homologation'): array
    {
        $company=CompanySetting::current();
        $company->update([
            'document'=>'12345678000100','legal_name'=>'Empresa Teste',
            'state'=>'BA','city'=>'Salvador',
        ]);
        $nature=OperationNature::query()->create([
            'name'=>'VENDA DE MERCADORIA','operation_type'=>'outbound',
            'purpose'=>'normal','cfop_internal'=>'5102','is_active'=>true,
        ]);
        $customer=Customer::query()->create([
            'name'=>'Cliente Teste','document'=>'12345678000190',
            'is_customer'=>true,'state'=>'BA','city'=>'Salvador',
        ]);
        $user=User::query()->create([
            'name'=>'Fiscal Responsável','email'=>'nfe-cancel@example.test',
            'password'=>'secret123','role'=>'admin','is_active'=>true,
        ]);
        // Access key fixture: the issuer CNPJ and model 55 occupy their
        // official positions; no fabricated signature or authorization.
        $key='292610'.'12345678000100'.'55'.str_repeat('0',22);
        $draft=NfeDraft::query()->create([
            'user_id'=>$user->id,'operation_nature_id'=>$nature->id,'customer_id'=>$customer->id,
            'status'=>$status,'environment'=>$environment,'series'=>1,'document_number'=>42,
            'access_key'=>$key,'protocol'=>'123456789012345','authorized_at'=>now(),
        ]);
        return [$user,$draft];
    }

    private function validRequest(): array
    {
        return [
            'reason'=>'Operação comercial não realizada pelo destinatário',
            'current_password'=>'secret123',
            'operation_not_completed'=>'1',
        ];
    }

    public function test_authenticated_admin_can_queue_a_single_homologation_cancellation(): void
    {
        Bus::fake();
        [$user,$draft]=$this->draft();
        $this->actingAs($user)->post(route('fiscal.nfe.cancel',$draft),$this->validRequest())
            ->assertRedirect(route('fiscal.nfe.edit',$draft));
        $draft->refresh();
        self::assertSame('authorized',$draft->status);
        self::assertSame('pending',$draft->cancellation_status);
        self::assertNotNull($draft->cancellation_requested_at);
        self::assertNull($draft->cancellation_attempted_at);
        Bus::assertDispatched(ProcessNfe55CancellationJob::class,
            fn(ProcessNfe55CancellationJob $job)=>$job->draftId===$draft->id);

        $this->post(route('fiscal.nfe.cancel',$draft),$this->validRequest())
            ->assertSessionHasErrors('nfe');
        Bus::assertDispatchedTimes(ProcessNfe55CancellationJob::class,1);
    }

    public function test_cancel_requires_password_explicit_confirmation_and_administrative_permission(): void
    {
        Bus::fake();
        [$admin,$draft]=$this->draft();

        $payload=$this->validRequest();
        $payload['current_password']='invalid';
        $this->actingAs($admin)->post(route('fiscal.nfe.cancel',$draft),$payload)
            ->assertSessionHasErrors('current_password');
        $payload=$this->validRequest();
        unset($payload['operation_not_completed']);
        $this->post(route('fiscal.nfe.cancel',$draft),$payload)
            ->assertSessionHasErrors('operation_not_completed');

        $operator=User::query()->create([
            'name'=>'Operador Fiscal','email'=>'nfe-operator@example.test',
            'password'=>'secret123','role'=>'operator','is_active'=>true,
            'permissions'=>['fiscal'],
        ]);
        $this->actingAs($operator)->post(route('fiscal.nfe.cancel',$draft),$this->validRequest())
            ->assertForbidden();
        self::assertNull($draft->fresh()->cancellation_status);
        Bus::assertNotDispatched(ProcessNfe55CancellationJob::class);
    }

    public function test_cancel_refuses_draft_and_unreleased_production(): void
    {
        Bus::fake();
        [$user,$draft]=$this->draft('draft');
        $this->actingAs($user)->post(route('fiscal.nfe.cancel',$draft),$this->validRequest())
            ->assertSessionHasErrors('nfe');
        $draft->update(['status'=>'authorized','environment'=>'production']);
        $this->post(route('fiscal.nfe.cancel',$draft),$this->validRequest())
            ->assertSessionHasErrors('nfe');
        self::assertNull($draft->fresh()->cancellation_requested_at);
        Bus::assertNotDispatched(ProcessNfe55CancellationJob::class);
    }

    public function test_cancellation_modal_does_not_enable_editing_an_authorized_note(): void
    {
        [$user,$draft]=$this->draft();
        $this->actingAs($user)->get(route('fiscal.nfe.edit',$draft))
            ->assertOk()
            ->assertSee('nfeCancelDialog')
            ->assertSee('data-nfe-allow-readonly',false)
            ->assertSee('name="current_password"',false);
        $this->put(route('fiscal.nfe.update',$draft),[])->assertStatus(409);
    }

    public function test_cancellation_worker_never_calls_sefaz_twice_after_claim(): void
    {
        [$user,$draft]=$this->draft();
        $draft->update([
            'cancellation_status'=>'processing',
            'cancellation_attempted_at'=>now(),
        ]);
        $this->actingAs($user);
        app(\App\Services\Fiscal\Nfe55CancellationService::class)->process($draft->id);
        self::assertSame('processing',$draft->fresh()->cancellation_status);
    }
}
