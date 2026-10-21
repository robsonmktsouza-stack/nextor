<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ControlTooltipTest extends TestCase
{
    use RefreshDatabase;

    private function authenticate(): void
    {
        $this->actingAs(User::create([
            'name' => 'Administrador',
            'email' => 'admin-tooltip@example.test',
            'password' => 'senha-longa-para-teste',
        ]));
    }

    public function test_all_control_screens_load_same_custom_tooltip_component(): void
    {
        $this->authenticate();

        foreach ([
            '/dashboard', '/clients', '/licenses', '/plans',
            '/automations', '/history', '/clients/create', '/plans/create',
        ] as $path) {
            $response = $this->get($path)->assertOk()
                ->assertSee('control-tooltip.js', false)
                ->assertSee('control-tooltip.css', false)
                ->assertSee('data-tooltip="Sair"', false)
                ->assertSee('aria-label="Sair"', false);

            $this->assertStringNotContainsString(' title="', $response->getContent(), $path);
        }
    }

    public function test_shared_toolbar_and_row_actions_do_not_use_native_browser_titles(): void
    {
        $this->authenticate();
        $this->get('/licenses')
            ->assertOk()
            ->assertSee('data-tooltip="Imprimir esta listagem"', false)
            ->assertSee('data-tooltip="Exportar página atual em CSV"', false)
            ->assertSee('data-tooltip="Atualizar listagem"', false)
            ->assertSee('data-tooltip="Filtros da listagem"', false)
            ->assertDontSee(' title="Imprimir esta listagem"', false);

        $this->get('/plans')
            ->assertOk()
            ->assertSee('data-tooltip="Imprimir esta listagem"', false)
            ->assertDontSee(' title="Atualizar listagem"', false);
    }

    public function test_js_handles_hover_keyboard_and_ajax_and_uses_safe_text(): void
    {
        $js = file_get_contents(public_path('js/control-tooltip.js'));
        $css = file_get_contents(public_path('css/control-tooltip.css'));
        $this->assertNotFalse($js);
        $this->assertNotFalse($css);
        $this->assertStringContainsString('new MutationObserver(', $js);
        $this->assertStringContainsString("addEventListener('focusin'", $js);
        $this->assertStringContainsString("addEventListener('pointerover'", $js);
        $this->assertStringContainsString('tooltip.textContent = content', $js);
        $this->assertStringContainsString("element.removeAttribute('title')", $js);
        $this->assertStringContainsString('.nextor-tooltip', $css);
        $this->assertStringContainsString('prefers-reduced-motion', $css);
    }
}
