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
    use \Tests\Concerns\WithConfiguredCompany;

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
            ->assertSee('Minha empresa')
            ->assertSee('Vendas e estoque')
            ->assertSee('Notas fiscais')
            ->assertSee('Equipe e acessos');

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
            ->assertSee('Gerar arquivo contábil')
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

    public function test_card_catalog_migration_preserves_existing_customizations_and_backfills_only_missing_codes(): void
    {
        $credit=PaymentMethod::query()->where('code','credit_card')->firstOrFail();
        $credit->update([
            'kind'=>'card','name'=>'Crédito personalizado',
            'fee_percent'=>'3.5000','settlement_days'=>30,
            'is_active'=>false,'pdv_enabled'=>false,'sort_order'=>87,
        ]);
        PaymentMethod::query()->where('code','debit_card')->delete();

        $migration=require database_path('migrations/2026_10_19_210000_reconcile_card_payment_kinds.php');
        $migration->up();
        $migration->up();

        $credit->refresh();
        self::assertSame('credit_card',$credit->kind);
        self::assertSame('Crédito personalizado',$credit->name);
        self::assertSame('3.5000',$credit->fee_percent);
        self::assertSame(30,$credit->settlement_days);
        self::assertFalse($credit->is_active);
        self::assertFalse($credit->pdv_enabled);
        self::assertSame(87,$credit->sort_order);
        self::assertSame(1,PaymentMethod::where('code','credit_card')->count());
        self::assertSame(1,PaymentMethod::where('code','debit_card')->count());
        self::assertSame('debit_card',PaymentMethod::where('code','debit_card')->value('kind'));
    }

    public function test_settings_accept_distinct_cards_but_not_new_ambiguous_card_type(): void
    {
        $this->actingAs($this->admin())
            ->get(route('settings.index',['tab'=>'payments']))
            ->assertOk()
            ->assertSee('Cartão de crédito')
            ->assertSee('Cartão de débito');

        $payload=[
            'code'=>'credit-custom','name'=>'Crédito da loja',
            'kind'=>'credit_card','fee_percent'=>'0',
            'fee_fixed'=>'0','settlement_days'=>0,'sort_order'=>80,
            'is_active'=>'1','pdv_enabled'=>'1',
        ];
        $this->post(route('settings.payment-methods.store'),$payload)->assertSessionHasNoErrors();
        self::assertSame('credit_card',PaymentMethod::where('code','credit-custom')->value('kind'));

        $this->post(route('settings.payment-methods.store'),array_replace($payload,[
            'code'=>'generic-new','kind'=>'card',
        ]))->assertSessionHasErrors('kind');
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
