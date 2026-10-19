<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithConfiguredCompany;
use Tests\TestCase;

/**
 * Protege o único componente de seleção utilizado em toda a aplicação.
 * A lista flutua na top layer, mas o <select> nativo continua responsável
 * pelo valor submetido aos formulários e pela validação do Laravel.
 */
final class SelectOverlayTest extends TestCase
{
    use RefreshDatabase;
    use WithConfiguredCompany;

    public function test_settings_crt_keeps_native_field_and_loads_global_select_component(): void
    {
        $user = User::query()->create([
            'name' => 'Administrador da interface',
            'email' => 'select-overlay@example.com',
            'password' => 'SenhaSegura123',
            'role' => 'admin',
            'is_active' => true,
        ]);

        $html = $this->actingAs($user)
            ->get(route('settings.index', ['tab' => 'general']))
            ->assertOk()
            ->getContent();

        self::assertIsString($html);
        self::assertStringContainsString('Regime da nota (CRT)', $html);
        self::assertStringContainsString('<select name="crt">', $html);
        self::assertStringContainsString('js/erp.js', $html);
        self::assertStringContainsString('css/desktop-polish.css', $html);
    }

    public function test_select_menu_uses_browser_top_layer_without_moving_native_select(): void
    {
        $javascript = file_get_contents(public_path('js/erp.js'));
        self::assertNotFalse($javascript);

        foreach ([
            "menu.setAttribute('popover','manual')",
            'menu.showPopover()',
            'menu.hidePopover()',
            "menu.matches(':popover-open')",
            "wrapper._nextorHideMenu",
            "wrapper.appendChild(menu)",
            "select.dispatchEvent(new Event('change',{bubbles:true}))",
            "trigger.setAttribute('aria-controls',menu.id)",
            "trigger.setAttribute('aria-expanded','false')",
            "document.querySelectorAll('select').forEach(enhanceSelect)",
            'window.NextorEnhanceSelect=enhanceSelect',
            'new MutationObserver',
        ] as $token) {
            self::assertStringContainsString($token, $javascript, $token);
        }

        self::assertStringNotContainsString('trigger.select();', $javascript);
    }

    public function test_dropdown_flips_above_fixed_save_bar_and_closes_with_modals(): void
    {
        $javascript = file_get_contents(public_path('js/erp.js'));
        self::assertNotFalse($javascript);

        foreach ([
            "'.settings-savebar, .editor-savebar'",
            "'.dialog-footer, .nfe-dialog-footer, .settings-savebar, .editor-savebar'",
            'wrapper.classList.toggle(\'drop-up\',dropUp)',
            "menu.style.top=",
            "menu.style.maxHeight=",
            "window.visualViewport?.addEventListener('resize'",
            "window.visualViewport?.addEventListener('scroll'",
            "document.addEventListener('close'",
            'wrapper._nextorHideMenu?.();',
            'useTopLayer=false;',
        ] as $token) {
            self::assertStringContainsString($token, $javascript);
        }
    }

    public function test_top_layer_visuals_do_not_make_dialog_overflow_visible(): void
    {
        $css = file_get_contents(public_path('css/desktop-polish.css'));
        self::assertNotFalse($css);

        foreach ([
            '.ui-select .ui-select-menu[popover]',
            'position:fixed;',
            '.ui-select .ui-select-menu[popover]:popover-open',
            '.ui-select .ui-select-menu[popover][hidden]',
            '.ui-select .ui-select-menu[popover] .ui-select-option.selected',
            'overscroll-behavior:contain',
        ] as $token) {
            self::assertStringContainsString($token, $css);
        }

        self::assertStringNotContainsString('.erp-dialog{overflow:visible!important}', $css);
        $withoutComments = preg_replace('~/\\*.*?\\*/~s', '', $css);
        self::assertNotNull($withoutComments);
        self::assertSame(substr_count($withoutComments, '{'), substr_count($withoutComments, '}'));
    }
}
