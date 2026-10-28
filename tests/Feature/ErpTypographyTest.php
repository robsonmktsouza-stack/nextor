<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ErpTypographyTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\WithConfiguredCompany;

    private function admin(): User
    {
        return User::query()->create([
            'name' => 'Administrador visual',
            'email' => 'erp-font-test@example.com',
            'password' => 'senhaSegura123',
            'role' => 'admin',
            'is_active' => true,
        ]);
    }

    public function test_erp_and_pdv_load_font_tokens_before_components_and_shared_controls_after_typography(): void
    {
        $this->actingAs($this->admin());

        foreach ([
            route('finance.dashboard'),
            route('products.index'),
            route('customers.index'),
            route('fiscal.index', ['tab' => 'nfce']),
            route('settings.index'),
            route('pdv.index'),
        ] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            self::assertIsString($html);

            self::assertStringContainsString('fonts.googleapis.com/css2?family=Roboto', $html);
            self::assertStringContainsString('css/erp-typography.css', $html);
            self::assertStringContainsString('css/desktop-polish.css', $html);
            self::assertStringContainsString('css/ui-tokens.css', $html);
            self::assertStringContainsString('css/ui-refinement.css', $html);
            self::assertLessThan(strpos($html, 'css/erp.css'), strpos($html, 'css/ui-tokens.css'));
            self::assertLessThan(
                strpos($html, 'css/erp-typography.css'),
                strpos($html, 'css/desktop-polish.css'),
                'A hierarquia de textos deve ser carregada após a estrutura desktop.'
            );
            self::assertLessThan(strpos($html, 'css/ui-refinement.css'), strpos($html, 'css/erp-typography.css'));
        }
    }

    public function test_login_and_recovery_pages_use_the_same_font(): void
    {
        foreach ([
            route('login'),
            route('password.request'),
            route('password.reset', ['token' => 'visual-font-test']),
        ] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            self::assertIsString($html);
            self::assertStringContainsString('fonts.googleapis.com/css2?family=Roboto', $html);
            self::assertStringContainsString('css/login.css', $html);
            self::assertStringContainsString('css/ui-tokens.css', $html);
            self::assertLessThan(strpos($html, 'css/login.css'), strpos($html, 'css/ui-tokens.css'));
        }
    }

    public function test_typography_stays_legible_without_rewriting_printed_fiscal_documents(): void
    {
        $css = file_get_contents(public_path('css/erp-typography.css'));
        $tokens = file_get_contents(public_path('css/ui-tokens.css'));
        self::assertNotFalse($css);
        self::assertNotFalse($tokens);

        foreach (['--lumeron-text:14px', '--lumeron-list:13px', '--lumeron-label:13px'] as $token) {
            self::assertStringContainsString($token, $tokens);
        }
        // Typography inherits canonical tokens; it must not compete with component defaults.
        self::assertDoesNotMatchRegularExpression('/--(?:lumeron|ui)-[a-z-]+\s*:/', $css);

        foreach ([
            '--lumeron-font',
            '.app-root .sidebar-link',
            '.app-root .cms-table .table-title',
            '.app-root .cms-table .table-subtitle',
            '.app-root .finance-stats .stat-label',
            '.app-root .finance-stats .stat-card small',
            '.lumeron-pdv-page .pdv-topbar-status',
            '@media screen',
            'max-width:760px',
        ] as $token) {
            self::assertStringContainsString($token, $css, $token.' ausente');
        }

        self::assertStringNotContainsString('@page', $css);
        self::assertStringNotContainsString('nfce-danfe.css', $css);
        self::assertStringNotContainsString('font-size:10px!important', $css);
        self::assertStringNotContainsString('.app-root .field', $css);
        self::assertStringNotContainsString('.app-root .dialog-header h2', $css);
        self::assertStringNotContainsString('tbody td:not(.empty-cell)', $css);

        $clean = preg_replace('~/\\*.*?\\*/~s', '', $css);
        self::assertNotNull($clean);
        self::assertSame(substr_count($clean, '{'), substr_count($clean, '}'));
    }

    public function test_all_user_interface_styles_remove_legacy_segoe_and_open_sans(): void
    {
        foreach ([
            'erp.css',
            'ui-tokens.css',
            'ui-refinement.css',
            'desktop-polish.css',
            'erp-typography.css',
            'pdv.css',
            'pdv-lumeron.css',
            'login.css',
        ] as $file) {
            $css = file_get_contents(public_path('css/'.$file));
            self::assertNotFalse($css);
            self::assertStringNotContainsString('Segoe UI', $css, $file);
            self::assertStringNotContainsString('Open Sans', $css, $file);
        }
    }
}
