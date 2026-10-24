<?php

namespace Tests\Feature;

use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithConfiguredCompany;
use Tests\TestCase;

final class NfeRecipientQuickEditTest extends TestCase
{
    use RefreshDatabase, WithConfiguredCompany;

    private function setupUserAndCompany(): void
    {
        $this->actingAs(User::query()->create([
            'name'=>'Operador de NF-e',
            'email'=>'nfecustomeredit@example.test',
            'password'=>'password',
            'role'=>'admin',
            'is_active'=>true,
        ]));
        CompanySetting::current()->update([
            'legal_name'=>'Empresa Teste de NF-e',
            'document'=>'12345678000100',
            'state_registration'=>'12345678',
            'state'=>'BA',
            'city'=>'Salvador',
            'city_ibge_code'=>'2927408',
            'address'=>'Rua Teste',
            'address_number'=>'10',
            'district'=>'Centro',
            'zip_code'=>'40000000',
            'crt'=>'1',
        ]);
    }

    private function customer(): Customer
    {
        return Customer::query()->create([
            'name'=>'Cliente Original',
            'document'=>'12345678909',
            'is_customer'=>true,
            'final_consumer'=>true,
            'ie_indicator'=>'non_contributor',
            'city'=>'São Paulo',
            'state'=>'SP',
            'address'=>'Rua Antiga',
            'address_number'=>'200',
            'zip_code'=>'01001000',
            'country_code'=>'1058',
            'country_name'=>'BRASIL',
        ]);
    }

    public function test_recipient_can_be_edited_in_place_without_creating_another_customer(): void
    {
        $this->setupUserAndCompany();
        $customer=$this->customer();
        $this->get(route('fiscal.nfe.create'))
            ->assertOk()->assertSee('id="nfeEditCustomer"',false);

        $response=$this->putJson(route('quick-catalog.customers.update',$customer),[
            'name'=>'Cliente Atualizado',
            'document'=>'123.456.789-09',
            'ie_indicator'=>'non_contributor',
            'state'=>'SP',
            'city'=>'São Paulo',
            'city_ibge_code'=>'3550308',
            'address'=>'Rua Atualizada',
            'address_number'=>'300',
            'address_complement'=>'Sala 1',
            'district'=>'Centro',
            'zip_code'=>'01001-000',
            'phone'=>'11999999999',
            'email'=>'cliente@example.test',
        ]);
        $response->assertOk()
            ->assertJsonPath('item.id',$customer->id)
            ->assertJsonPath('item.city_ibge_code','3550308')
            ->assertJsonPath('item.document','12345678909')
            ->assertJsonPath('item.address','Rua Atualizada');
        self::assertSame(1,Customer::query()->count());
        $customer->refresh();
        self::assertTrue($customer->final_consumer);
        self::assertSame('Rua Atualizada',$customer->address);
        self::assertSame('3550308',$customer->city_ibge_code);
        self::assertSame('cliente@example.test',$customer->email);
    }

    public function test_editing_customer_cannot_reuse_another_customers_document(): void
    {
        $this->setupUserAndCompany();
        $customer=$this->customer();
        Customer::query()->create([
            'name'=>'Outro Cliente',
            'document'=>'12345678000190',
            'is_customer'=>true,
            'ie_indicator'=>'non_contributor',
        ]);

        $this->putJson(route('quick-catalog.customers.update',$customer),[
            'name'=>'Cliente Original',
            'document'=>'12.345.678/0001-90',
            'ie_indicator'=>'non_contributor',
        ])->assertUnprocessable()->assertJsonValidationErrors('document');

        self::assertSame('12345678909',$customer->fresh()->document);
        self::assertSame(2,Customer::query()->count());
    }
}
