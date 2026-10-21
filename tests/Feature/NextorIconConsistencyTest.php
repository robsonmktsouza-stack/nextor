<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class NextorIconConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private function login(): void
    {
        $this->actingAs(User::create([
            'name' => 'Administrador',
            'email' => 'nextor-icons@example.test',
            'password' => 'senha-longa-de-testes',
        ]));
    }

    public function test_all_admin_sections_render_native_nextor_icons_without_legacy_sprite_symbols(): void
    {
        $this->login();
        foreach ([
            '/dashboard', '/clients', '/licenses', '/plans',
            '/automations', '/history', '/clients/create', '/plans/create',
        ] as $url) {
            $response = $this->get($url)->assertOk()
                ->assertSee('class="nextor-icon', false)
                ->assertDontSee('class="icon-defs"', false)
                ->assertDontSee('href="#i-', false);
            $this->assertStringContainsString('stroke-width="1.8"', $response->getContent(), $url);
        }
    }

    public function test_license_toolbar_and_row_actions_use_same_real_svg_paths_as_products_in_nextor(): void
    {
        $this->login();
        $html = $this->get('/licenses')->assertOk()->getContent();

        // Original print, export, refresh, search and add SVG path data from
        // the ERP's resources/views/partials/icon.blade.php (branch develop).
        $this->assertStringContainsString('M6 9V3h12v6', $html);
        $this->assertStringContainsString('M12 3v12M7 10l5 5 5-5', $html);
        $this->assertStringContainsString('M20 6v5h-5M4 18v-5h5', $html);
        $this->assertStringContainsString('m20 20-4-4', $html);
        $this->assertStringContainsString('M12 5v14M5 12h14', $html);
    }
}
