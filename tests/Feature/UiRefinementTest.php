<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UiRefinementTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\WithConfiguredCompany;

    private function admin(): User
    {
        return User::query()->create([
            'name'=>'Administrador de interface',
            'email'=>'ui-check@example.com',
            'password'=>'senhaSegura123',
            'role'=>'admin',
            'is_active'=>true,
        ]);
    }

    public function test_shared_components_are_loaded_after_base_typography_and_module_styles(): void
    {
        $this->actingAs($this->admin());

        foreach ([
            route('products.index')=>['css/erp.css','css/ui-refinement.css'],
            route('settings.index')=>['css/erp.css','css/ui-refinement.css'],
            route('purchases.create')=>['css/purchases.css','css/ui-refinement.css'],
            route('fiscal.index',['tab'=>'nfce'])=>['css/erp.css','css/ui-refinement.css'],
            route('pdv.index')=>['css/pdv.css','css/ui-refinement.css'],
        ] as $url=>[$base,$refinement]) {
            $response=$this->get($url)->assertOk();
            $html=$response->getContent();
            self::assertNotFalse($html);
            self::assertStringContainsString($base,$html);
            self::assertStringContainsString($refinement,$html);
            self::assertLessThan(
                strpos($html,$refinement),
                strpos($html,$base),
                'O refinamento deve vir depois da folha de estilos base.'
            );
            self::assertStringContainsString('css/ui-tokens.css', $html);
            self::assertStringContainsString('css/erp-typography.css', $html);
            self::assertLessThan(strpos($html, 'css/erp.css'), strpos($html, 'css/ui-tokens.css'));
            self::assertLessThan(strpos($html, $refinement), strpos($html, 'css/erp-typography.css'));
            preg_match_all('~<link\b[^>]*href=["\x27]([^"\x27]+\.css(?:\?[^"\x27]*)?)["\x27][^>]*>~i', $html, $stylesheets);
            foreach ($stylesheets[1] as $stylesheet) {
                if (str_contains($stylesheet, '/css/') && !str_contains($stylesheet, 'ui-refinement.css') && !str_contains($stylesheet, 'commercial-readonly.css')) {
                    self::assertLessThan(strpos($html, $refinement), strpos($html, $stylesheet), $stylesheet.' precisa anteceder os componentes compartilhados.');
                }
            }
        }
    }

    public function test_visual_tokens_cover_real_controls_without_changing_fiscal_printouts(): void
    {
        $css=file_get_contents(public_path('css/ui-refinement.css'));
        self::assertNotFalse($css);
        foreach ([
            '--ui-control-height',
            '.btn{',
            '.app-root .editor-tabs',
            '.app-root .editor-tab.active',
            '.app-root .grid-tool',
            '.app-root .cms-table',
            ':is(.field,.purchase-line-field)',
            ':is(.cms-table,.purchase-show-table)',
            '.app-root .grid-actions-right',
            '#nfceEditor .nfce-savebar',
            '.pdv-body .pdv-topbar-button',
            '.pdv-body .pdv-modal-primary',
            'prefers-reduced-motion',
        ] as $token) {
            self::assertStringContainsString($token,$css,$token.' ausente');
        }
        // The real DANFE has its own fiscal stylesheet and page geometry.
        self::assertStringNotContainsString('nfce-danfe.css',$css);
        self::assertStringNotContainsString('@page',$css);
        self::assertStringContainsString('@media screen', $css);
        self::assertDoesNotMatchRegularExpression('/--(?:lumeron|ui)-[a-z-]+\s*:/', $css);
    }

    public function test_list_rows_share_nfce_density_and_keep_editable_tables_excluded(): void
    {
        $css=file_get_contents(public_path('css/ui-refinement.css'));
        $tokens=file_get_contents(public_path('css/ui-tokens.css'));
        self::assertNotFalse($css);
        self::assertNotFalse($tokens);
        foreach ([
            '--ui-list-header-height:38px',
            '--ui-list-row-height:46px',
            '--ui-list-cell-pad-y:6px',
            '--ui-list-row-height:50px',
            '.system-comfortable{',
            '--ui-control-height:var(--ui-control-height-comfortable)',
        ] as $expected) {
            self::assertStringContainsString($expected, $tokens);
        }
        foreach ([
            '.app-root .table-scroll .cms-table thead th',
            '.app-root .table-scroll .cms-table tbody td:not(.empty-cell)',
            '.app-root .table-scroll .cms-table tbody .table-title',
            '.app-root .table-scroll .cms-table tbody .table-subtitle',
            '.app-root .table-scroll .cms-table tbody td:has(> :is(input,select,textarea,.ui-select,.numeric-input))',
        ] as $expected) {
            self::assertStringContainsString($expected,$css);
        }
        self::assertStringNotContainsString('.sale-cart-table tbody td{',$css);
        self::assertStringNotContainsString('.pdv-cart-row{',$css);
    }

    public function test_listings_do_not_show_total_or_selected_count_badges(): void
    {
        $this->actingAs($this->admin());

        foreach ([
            route('products.index'),
            route('stock.index'),
            route('sales.index'),
            route('fiscal.index',['tab'=>'nfce']),
        ] as $url) {
            $response=$this->get($url)->assertOk();
            $response->assertDontSee('data-selection-count',false);
            $response->assertDontSee('table-records-count',false);
            $response->assertDontSee('page-title-meta',false);
            $response->assertDontSee('Exibindo');
            $response->assertSee('table-page-size-select',false);
            $response->assertSee('table-pager-compact',false);
            // Removing the visual counters does not disable bulk selections.
            $response->assertSee('data-check-all',false);
        }

        $js=file_get_contents(public_path('js/erp.js'));
        self::assertNotFalse($js);
        self::assertStringNotContainsString("const count=card.querySelector('[data-selection-count]')",$js);
        self::assertStringContainsString('selected.length===0',$js);
        self::assertStringContainsString("card.addEventListener('change'",$js);

        $footer=file_get_contents(resource_path('views/partials/table-footer.blade.php'));
        self::assertNotFalse($footer);
        self::assertStringNotContainsString('table-records-count',$footer);
        self::assertStringContainsString('per_page',$footer);
    }

    public function test_all_shared_css_files_have_balanced_blocks(): void
    {
        foreach (['ui-tokens.css','erp.css','erp-typography.css','ui-refinement.css','pdv.css'] as $file) {
            $css=file_get_contents(public_path('css/'.$file));
            self::assertNotFalse($css);
            $css=preg_replace('~/\*.*?\*/~s','',$css);
            self::assertNotNull($css);
            self::assertSame(
                substr_count($css,'{'),
                substr_count($css,'}'),
                $file.' possui um bloco CSS sem fechamento ou chave excedente.'
            );
        }
    }

    public function test_desktop_structure_follows_existing_themes_before_shared_components(): void
    {
        $this->actingAs($this->admin());

        foreach ([
            route('products.index'),
            route('customers.index'),
            route('stock.index'),
            route('sales.index'),
            route('fiscal.index', ['tab' => 'nfe']),
            route('settings.index'),
        ] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            self::assertIsString($html);
            self::assertStringContainsString('css/desktop-polish.css', $html);
            self::assertStringContainsString('css/ui-refinement.css', $html);
            self::assertLessThan(strpos($html, 'css/ui-refinement.css'), strpos($html, 'css/desktop-polish.css'));
            self::assertLessThan(
                strpos($html, 'css/desktop-polish.css'),
                strpos($html, 'css/branding.css'),
                'O acabamento deve ser carregado depois da marca e do tema base.'
            );
        }

        $pdv = $this->get(route('pdv.index'))->assertOk()->getContent();
        self::assertIsString($pdv);
        self::assertStringContainsString('css/desktop-polish.css', $pdv);
        self::assertLessThan(strpos($pdv, 'css/ui-refinement.css'), strpos($pdv, 'css/desktop-polish.css'));
        self::assertLessThan(
            strpos($pdv, 'css/desktop-polish.css'),
            strpos($pdv, 'css/pdv-lumeron.css'),
            'O PDV precisa manter seu próprio tema antes do acabamento.'
        );
    }

    public function test_desktop_polish_preserves_print_and_covers_breakpoints(): void
    {
        $css = file_get_contents(public_path('css/desktop-polish.css'));
        self::assertNotFalse($css);

        foreach ([
            '@media screen',
            '.app-root .grid-actionbar',
            '.app-root .table-scroll',
            '.app-root .fiscal-empty-state',
            '.app-root .table-footerbar.table-footer-controls',
            '.app-root .settings-subtabs',
            '.lumeron-pdv-page .pdv-topbar',
            'max-width:1260px',
            'max-width:900px',
            'max-width:620px',
            'max-width:385px',
            'prefers-reduced-motion',
        ] as $token) {
            self::assertStringContainsString($token, $css);
        }
        self::assertStringNotContainsString('@page', $css);
        self::assertStringNotContainsString('nfce-danfe.css', $css);

        $withoutComments = preg_replace('~/\\*.*?\\*/~s', '', $css);
        self::assertNotNull($withoutComments);
        self::assertSame(substr_count($withoutComments, '{'), substr_count($withoutComments, '}'));
    }

    public function test_fiscal_empty_state_provides_only_supported_create_actions(): void
    {
        $this->actingAs($this->admin());

        $nfe = $this->get(route('fiscal.index', ['tab' => 'nfe']))->assertOk();
        $nfe->assertSee('class="fiscal-empty-state"', false);
        $nfe->assertSee('Criar nova NF-e');
        $nfe->assertSee(route('fiscal.nfe.create'), false);

        $nfce = $this->get(route('fiscal.index', ['tab' => 'nfce']))->assertOk();
        $nfce->assertSee('Criar nova NFC-e');
        $nfce->assertSee(route('fiscal.nfce.create'), false);

        $cte = $this->get(route('fiscal.index', ['tab' => 'cte']))->assertOk();
        $cte->assertSee('Nenhum documento fiscal encontrado');
        $cte->assertDontSee('Criar nova NF-e');
        $cte->assertDontSee('Criar nova NFC-e');
    }


    public function test_active_module_tab_enhancement_preserves_navigation_and_accessibility(): void
    {
        $this->actingAs($this->admin());
        $html = $this->get(route('settings.index', ['tab' => 'users']))->assertOk()->getContent();
        self::assertIsString($html);
        self::assertStringContainsString('js/desktop-polish.js', $html);
        self::assertLessThan(
            strpos($html, 'js/desktop-polish.js'),
            strpos($html, 'js/erp.js'),
            'O ajuste de navegação deve ser carregado após as interações principais.'
        );

        $js = file_get_contents(public_path('js/desktop-polish.js'));
        self::assertNotFalse($js);
        foreach ([
            '.sales-module-tabs',
            '.finance-module-tabs',
            '.settings-main-tabs',
            'a.active',
            "setAttribute('aria-current', 'page')",
            'tabs.scrollLeft',
            'DOMContentLoaded',
        ] as $expected) {
            self::assertStringContainsString($expected, $js);
        }
    }

}
