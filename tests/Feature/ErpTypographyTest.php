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

    public function test_erp_and_pdv_load_readable_font_after_all_visual_styles(): void
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
            self::assertLessThan(
                strpos($html, 'css/erp-typography.css'),
                strpos($html, 'css/desktop-polish.css'),
                'O tamanho da fonte precisa prevalecer sobre o acabamento desktop.'
            );
        }
    }

    public function test_login_and_recovery_pages_use_the_same_font(): void
    {
        foreach ([
            route('login'),
            route('password.request'),
        ] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            self::assertIsString($html);
            self::assertStringContainsString('fonts.googleapis.com/css2?family=Roboto', $html);
            self::assertStringContainsString('css/login.css', $html);
        }
    }

    public function test_typography_stays_legible_without_rewriting_printed_fiscal_documents(): void
    {
        $css = file_get_contents(public_path('css/erp-typography.css'));
        self::assertNotFalse($css);

        foreach ([
            '--lumeron-font',
            '--lumeron-text:14px',
            '--lumeron-list:13px',
            '.app-root .sidebar-link',
            '.app-root .table-scroll .cms-table thead th',
            '.app-root .table-scroll .cms-table tbody td:not(.empty-cell)',
            '.app-root .finance-stats .stat-label',
            '.app-root .finance-stats .stat-card small',
            '.app-root .field > span',
            '.lumeron-pdv-page .pdv-sales-table',
            '@media screen',
            'max-width:760px',
        ] as $token) {
            self::assertStringContainsString($token, $css, $token.' ausente');
        }

        self::assertStringNotContainsString('@page', $css);
        self::assertStringNotContainsString('nfce-danfe.css', $css);
        self::assertStringNotContainsString('font-size:10px!important', $css);

        $clean = preg_replace('~/\\*.*?\\*/~s', '', $css);
        self::assertNotNull($clean);
        self::assertSame(substr_count($clean, '{'), substr_count($clean, '}'));
    }

    public function test_all_user_interface_styles_remove_legacy_segoe_and_open_sans(): void
    {
        foreach ([
            'erp.css',
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
