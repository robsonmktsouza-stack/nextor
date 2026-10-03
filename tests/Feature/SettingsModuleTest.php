<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SettingsModuleTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::query()->create([
            'name'=>'Administrador',
            'email'=>'admin-settings@example.com',
            'password'=>'senhaSegura123',
            'role'=>'admin',
            'is_active'=>true,
        ]);
    }

    public function test_settings_requires_authentication(): void
    {
        $this->get('/settings')->assertRedirect('/login');
    }

    public function test_admin_can_open_complete_settings_center(): void
    {
        $admin=$this->admin();

        $this->actingAs($admin)
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee('Plano de contas')
            ->assertSee('Fiscal')
            ->assertSee('Tributação')
            ->assertSee('Contábil');

        $this->actingAs($admin)
            ->get(route('settings.index',['tab'=>'fiscal']))
            ->assertOk()
            ->assertSee('NF-e')
            ->assertSee('NFC-e')
            ->assertSee('NFS-e')
            ->assertSee('CT-e / MDF-e');
    }

    public function test_company_settings_are_persisted(): void
    {
        $this->actingAs($this->admin())
            ->post(route('settings.company.update'),[
                'legal_name'=>'Empresa Teste LTDA',
                'trade_name'=>'Empresa Teste',
                'document'=>'12345678000199',
                'state'=>'BA',
                'city'=>'Salvador',
                'timezone'=>'America/Bahia',
                'show_currency_prefix'=>'1',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('company_settings',[
            'legal_name'=>'Empresa Teste LTDA',
            'trade_name'=>'Empresa Teste',
            'state'=>'BA',
            'city'=>'Salvador',
            'timezone'=>'America/Bahia',
            'show_currency_prefix'=>1,
        ]);
    }

    public function test_secret_fiscal_settings_are_not_stored_in_plain_text(): void
    {
        $secret='CSC-SEGREDO-123456';

        $this->actingAs($this->admin())
            ->post(route('settings.group.update','nfce'),[
                'environment'=>'homologation',
                'series'=>1,
                'next_number'=>1,
                'csc_id'=>'1',
                'csc_token'=>$secret,
                'enabled'=>'1',
            ])
            ->assertSessionHasNoErrors();

        $row=DB::table('app_settings')
            ->where('group','nfce')
            ->where('key','csc_token')
            ->first();

        $this->assertNotNull($row);
        $this->assertNotSame($secret,$row->value);
        $this->assertSame($secret,AppSetting::value('nfce','csc_token'));
    }

    public function test_settings_do_not_expose_unimplemented_operational_fields(): void
    {
        $admin=$this->admin();

        $this->actingAs($admin)
            ->get(route('settings.index',['tab'=>'billing']))
            ->assertOk()
            ->assertSee('Cobranças e PIX')
            ->assertDontSee('API key')
            ->assertDontSee('API secret')
            ->assertDontSee('name="provider"',false);

        $this->actingAs($admin)
            ->get(route('settings.index',['tab'=>'accounting']))
            ->assertOk()
            ->assertSee('Formato disponível')
            ->assertDontSee('name="export_format"',false);

        $this->actingAs($admin)
            ->get(route('settings.index',['tab'=>'integrations']))
            ->assertOk()
            ->assertDontSee('Integração contábil');
    }

    public function test_payment_methods_can_be_configured(): void
    {
        $this->actingAs($this->admin())
            ->post(route('settings.payment-methods.store'),[
                'code'=>'voucher',
                'name'=>'Voucher',
                'kind'=>'other',
                'fee_percent'=>'1.5000',
                'fee_fixed'=>'0.50',
                'settlement_days'=>2,
                'sort_order'=>80,
                'is_active'=>'1',
                'pdv_enabled'=>'1',
            ])
            ->assertSessionHasNoErrors();

        $method=PaymentMethod::query()->where('code','voucher')->firstOrFail();
        $this->assertSame('Voucher',$method->name);
        $this->assertTrue($method->is_active);
        $this->assertTrue($method->pdv_enabled);
        $this->assertSame(2,$method->settlement_days);
    }

    public function test_module_permissions_are_enforced(): void
    {
        $user=User::query()->create([
            'name'=>'Vendedor',
            'email'=>'seller-settings@example.com',
            'password'=>'senhaSegura123',
            'role'=>'sales',
            'is_active'=>true,
            'permissions'=>['sales'],
        ]);

        $this->actingAs($user)->get(route('sales.index'))->assertOk();
        $this->actingAs($user)->get(route('settings.index'))->assertForbidden();
        $this->actingAs($user)->get(route('finance.dashboard'))->assertForbidden();
    }

    public function test_settings_only_user_can_manage_finance_configuration_tables(): void
    {
        $user=User::query()->create([
            'name'=>'Configurador',
            'email'=>'settings-only@example.com',
            'password'=>'senhaSegura123',
            'role'=>'manager',
            'is_active'=>true,
            'permissions'=>['settings'],
        ]);

        $this->actingAs($user)
            ->post(route('finance.categories.store'),[
                'name'=>'Receita configurada',
                'type'=>'income',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('financial_categories',[
            'name'=>'Receita configurada',
            'type'=>'income',
        ]);
    }
}
