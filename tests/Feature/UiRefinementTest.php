<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UiRefinementTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_shared_style_is_loaded_after_erp_in_admin_and_after_pdv_in_cashier(): void
    {
        $this->actingAs($this->admin());

        foreach ([
            route('products.index')=>['css/erp.css','css/ui-refinement.css'],
            route('settings.index')=>['css/erp.css','css/ui-refinement.css'],
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
        }
    }

    public function test_visual_tokens_cover_real_controls_without_changing_fiscal_printouts(): void
    {
        $css=file_get_contents(public_path('css/ui-refinement.css'));
        self::assertNotFalse($css);
        foreach ([
            '--ui-control-height',
            '.app-root .btn',
            '.app-root .editor-tabs',
            '.app-root .editor-tab.active',
            '.app-root .grid-tool',
            '.app-root .cms-table',
            '.app-root .field',
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
    }

    public function test_list_rows_share_nfce_density_and_keep_editable_tables_excluded(): void
    {
        $css=file_get_contents(public_path('css/ui-refinement.css'));
        self::assertNotFalse($css);
        foreach ([
            '--ui-list-header-height:38px',
            '--ui-list-row-height:46px',
            '--ui-list-cell-pad-y:6px',
            'body.system-comfortable .app-root',
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

    public function test_all_shared_css_files_have_balanced_blocks(): void
    {
        foreach (['erp.css','ui-refinement.css','pdv.css'] as $file) {
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
}
