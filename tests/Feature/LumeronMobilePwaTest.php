<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithConfiguredCompany;
use Tests\TestCase;

final class LumeronMobilePwaTest extends TestCase
{
    use RefreshDatabase;
    use WithConfiguredCompany;

    public function test_manifest_is_scoped_to_the_current_company_origin(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(public_path('manifest.webmanifest')),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertSame('Lumeron ERP', $manifest['name']);
        $this->assertSame('/', $manifest['id']);
        $this->assertSame('/', $manifest['start_url']);
        $this->assertSame('/', $manifest['scope']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('pt-BR', $manifest['lang']);

        // Relative URLs, not another company's domain.
        foreach ($manifest['icons'] as $icon) {
            $this->assertStringStartsWith('/', $icon['src']);
            $this->assertFileExists(public_path(ltrim($icon['src'], '/')));
        }
    }

    public function test_pwa_offline_cache_only_contains_public_shell(): void
    {
        $serviceWorker = (string) file_get_contents(public_path('sw.js'));
        $this->assertStringContainsString("const SHELL_ASSETS = [OFFLINE_URL]", $serviceWorker);
        $this->assertStringContainsString("request.mode !== 'navigate'", $serviceWorker);
        $this->assertStringContainsString('event.respondWith(fetch(request)', $serviceWorker);
        $this->assertStringNotContainsString('cache.put(request', $serviceWorker);
        $this->assertStringNotContainsString('caches.open(\'pages', $serviceWorker);
        $this->assertFileExists(public_path('pwa/offline.html'));
        $this->assertFileExists(public_path('js/mobile-pwa.js'));
    }

    public function test_login_and_administration_expose_the_manifest(): void
    {
        $this->get('/login')->assertOk()
            ->assertSee('manifest.webmanifest')
            ->assertSee('mobile-pwa.js');

        $user = User::query()->create([
            'name' => 'Administrador mobile',
            'username' => 'admin_mobile',
            'email' => 'mobile-test@example.com',
            'password' => 'SenhaSeguraMobile123',
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)->get('/dashboard')->assertOk()
            ->assertSee('manifest.webmanifest')
            ->assertSee('mobile.css')
            ->assertSee('Navegação rápida')
            ->assertSee('data-mobile-more');
    }

    public function test_mobile_listing_cards_are_opt_in_only(): void
    {
        foreach ([
            'products/index.blade.php',
            'services/index.blade.php',
            'customers/index.blade.php',
            'sales/index.blade.php',
            'stock/index.blade.php',
            'purchases/index.blade.php',
        ] as $view) {
            $source = (string) file_get_contents(resource_path('views/'.$view));
            $this->assertStringContainsString('data-mobile-cards', $source, $view);
        }

        $this->assertStringNotContainsString(
            'data-mobile-cards',
            (string) file_get_contents(resource_path('views/pdv/index.blade.php'))
        );
    }
}
