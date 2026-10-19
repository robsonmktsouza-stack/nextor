<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A navegação amigável muda apenas o texto e o agrupamento:
 * nomes de rotas, parâmetros, permissões e formulários não são alterados.
 */
final class SettingsClarityTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\WithConfiguredCompany;

    private function admin(): User
    {
        return User::query()->create([
            'name' => 'Administrador de configurações',
            'email' => 'settings-clarity@example.com',
            'password' => 'SenhaTesteSegura123',
            'role' => 'admin',
            'is_active' => true,
        ]);
    }

    public function test_settings_have_six_understandable_areas_and_preserve_existing_tab_routes(): void
    {
        $this->actingAs($this->admin());
        $html = $this->get(route('settings.index'))->assertOk()->getContent();

        self::assertIsString($html);
        foreach ([
            'Minha empresa',
            'Vendas e estoque',
            'Financeiro',
            'Notas fiscais',
            'Equipe e acessos',
            'Outras opções',
            'settings-section-guide',
            'Áreas de configurações',
        ] as $label) {
            self::assertStringContainsString($label, $html);
        }

        foreach ([
            'general', 'operations', 'chart', 'fiscal', 'users', 'system',
        ] as $tab) {
            self::assertStringContainsString(route('settings.index', ['tab' => $tab]), $html);
        }
    }

    public function test_each_previous_setting_page_is_still_reachable(): void
    {
        $this->actingAs($this->admin());

        foreach ([
            'general', 'printing', 'catalog',
            'operations', 'inventory', 'pdv',
            'chart', 'accounts', 'payments', 'billing', 'accounting',
            'fiscal', 'nfe', 'nfce', 'nfse', 'cte', 'tax',
            'users', 'system', 'integrations',
        ] as $tab) {
            $response = $this->get(route('settings.index', ['tab' => $tab]))->assertOk();
            $response->assertSee('settings-main-tabs', false);
            $response->assertSee('settings-section-guide', false);
        }
    }

    public function test_finance_settings_use_plain_language_but_keep_form_fields_and_post_routes(): void
    {
        $this->actingAs($this->admin());

        $this->get(route('settings.index', ['tab' => 'chart']))
            ->assertOk()
            ->assertSee('Categorias de receitas e despesas')
            ->assertSee('Categorias financeiras')
            ->assertSee('Caixa e bancos');

        $this->get(route('settings.index', ['tab' => 'payments']))
            ->assertOk()
            ->assertSee('Taxa percentual')
            ->assertSee('Receber após')
            ->assertSee('name="settlement_days"', false)
            ->assertSee('name="fee_percent"', false);

        $this->get(route('settings.index', ['tab' => 'operations']))
            ->assertOk()
            ->assertSee('Registrar valores das vendas no financeiro')
            ->assertSee('name="auto_finance_sale"', false);
    }

    public function test_fiscal_and_advanced_settings_explain_purpose_without_changing_technical_keys(): void
    {
        $this->actingAs($this->admin());

        $this->get(route('settings.index', ['tab' => 'nfce']))
            ->assertOk()
            ->assertSee('Nota fiscal do consumidor')
            ->assertSee('Código de segurança (CSC)')
            ->assertSee('name="csc_token"', false);

        $this->get(route('settings.index', ['tab' => 'tax']))
            ->assertOk()
            ->assertSee('Impostos e códigos fiscais')
            ->assertSee('CSOSN padrão')
            ->assertSee('name="icms_csosn_default"', false);

        $this->get(route('settings.index', ['tab' => 'system']))
            ->assertOk()
            ->assertSee('Aparência e preferências')
            ->assertSee('Tempo de espera da pesquisa')
            ->assertSee('name="search_delay"', false);

        $this->get(route('settings.index', ['tab' => 'integrations']))
            ->assertOk()
            ->assertSee('Integrações para desenvolvedores')
            ->assertSee('name="api_enabled"', false);
    }

    public function test_visual_styles_show_group_guides_and_keep_secondary_navigation_readable(): void
    {
        $css = file_get_contents(public_path('css/desktop-polish.css'));
        self::assertNotFalse($css);
        self::assertStringContainsString('.app-root .settings-section-guide', $css);
        self::assertStringContainsString('.app-root .settings-subtabs > a[aria-current="page"]', $css);
        self::assertStringContainsString('overflow-x:auto', $css);
    }
}
