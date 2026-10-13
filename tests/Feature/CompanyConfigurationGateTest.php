<?php

namespace Tests\Feature;

use App\Models\CompanySetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CompanyConfigurationGateTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::query()->create([
            'name'=>'Administrador',
            'email'=>'company-gate-admin@example.com',
            'password'=>'SenhaTeste123',
            'role'=>'admin',
            'is_active'=>true,
        ]);
    }

    public function test_unconfigured_company_does_not_block_dashboard_settings_or_catalogs(): void
    {
        $this->actingAs($this->admin());

        foreach([
            route('dashboard'),
            route('settings.index',['tab'=>'general']),
            route('products.index'),
            route('services.index'),
            route('customers.index'),
            route('stock.index'),
            route('fiscal.tax-groups.index'),
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_unconfigured_company_redirects_only_operational_modules_to_existing_settings(): void
    {
        $this->actingAs($this->admin());
        $settings=route('settings.index',['tab'=>'general']);

        foreach([
            route('pdv.index'),
            route('sales.index'),
            route('finance.dashboard'),
            route('fiscal.index'),
            route('sales.returns.index'),
        ] as $url) {
            $this->get($url)->assertRedirect($settings)
                ->assertSessionHas('warning',fn($message)=>
                    str_contains($message,'configure o CNPJ')
                    && str_contains($message,'Dados da empresa'));
        }

        // Ação por URL ou POST direto também deve ser protegida.
        $this->post(route('pdv.store'),[])->assertRedirect($settings);
    }

    public function test_partially_filled_company_still_requires_the_missing_fields(): void
    {
        CompanySetting::current()->update([
            'document'=>'12345678000199',
            'legal_name'=>'Empresa Parcial LTDA',
        ]);

        $this->actingAs($this->admin())->get(route('pdv.index'))
            ->assertRedirect(route('settings.index',['tab'=>'general']));
    }

    public function test_navigation_shows_warning_in_place_without_a_new_setup_page(): void
    {
        $this->actingAs($this->admin())
            ->get(route('dashboard'))->assertOk()
            ->assertSee('data-company-ready="0"',false)
            ->assertSee('data-company-required href="'.route('pdv.index').'"',false)
            ->assertSee('data-company-required href="'.route('sales.index').'"',false)
            ->assertSee('data-company-settings-url="'.route('settings.index',['tab'=>'general']).'"',false);

        $script=file_get_contents(public_path('js/erp.js'));
        self::assertIsString($script);
        self::assertStringContainsString('Configure a empresa primeiro',$script);
        self::assertStringContainsString('Configurar empresa',$script);

        CompanySetting::current()->update([
            'document'=>'12345678000199',
            'legal_name'=>'Empresa Configurada LTDA',
            'state'=>'BA',
            'city'=>'Salvador',
        ]);
        $this->get(route('dashboard'))->assertOk()
            ->assertSee('data-company-ready="1"',false);
    }

    public function test_registered_company_can_open_transaction_modules_without_a_wizard(): void
    {
        CompanySetting::current()->update([
            'document'=>'12345678000199',
            'legal_name'=>'Empresa Configurada LTDA',
            'state'=>'BA',
            'city'=>'Salvador',
        ]);

        $this->actingAs($this->admin())
            ->get(route('pdv.index'))->assertOk();
        $this->get(route('sales.index'))->assertOk();
        $this->get(route('finance.dashboard'))->assertOk();
        $this->get(route('fiscal.index'))->assertOk();
    }

    public function test_unconfigured_api_requests_return_an_explicit_json_error(): void
    {
        $this->actingAs($this->admin())
            ->getJson(route('pdv.sales.history'))
            ->assertStatus(409)
            ->assertJsonPath('code','company_not_configured')
            ->assertJsonPath('settings_url',route('settings.index',['tab'=>'general']));

        $this->postJson(route('pdv.store'),[])
            ->assertStatus(409)
            ->assertJsonPath('code','company_not_configured');
    }

    public function test_operator_without_settings_permission_gets_explanation_not_redirect_loop(): void
    {
        $operator=User::query()->create([
            'name'=>'Caixa',
            'email'=>'company-gate-cashier@example.com',
            'password'=>'SenhaTeste123',
            'role'=>'operator',
            'permissions'=>['pdv'],
            'is_active'=>true,
        ]);

        $this->actingAs($operator)
            ->get(route('pdv.index'))->assertForbidden();
        $this->getJson(route('pdv.sales.history'))
            ->assertStatus(409)
            ->assertJsonPath('settings_url',null);
    }

    public function test_company_identity_cannot_unlock_another_managed_installation(): void
    {
        config()->set('instance.cnpj','61288313000181');
        CompanySetting::current()->update([
            'document'=>'12345678000199',
            'legal_name'=>'Outra Empresa Ltda',
            'state'=>'BA',
            'city'=>'Salvador',
        ]);

        $this->actingAs($this->admin())
            ->get(route('pdv.index'))
            ->assertRedirect(route('settings.index',['tab'=>'general']));
    }
}
