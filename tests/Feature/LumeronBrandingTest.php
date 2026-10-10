<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class LumeronBrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_official_logo_and_elephant_assets_exist(): void
    {
        foreach (['logo.png','elephant.png'] as $file) {
            $path=public_path('images/lumeron/'.$file);
            self::assertFileExists($path);
            $image=getimagesize($path);
            self::assertIsArray($image);
            self::assertSame(IMAGETYPE_PNG,$image[2]);
            self::assertGreaterThan(100,$image[0]);
        }
        self::assertSame('Lumeron',config('app.name'));
    }

    public function test_login_uses_official_brand_not_previous_placeholder(): void
    {
        $this->get('/login')->assertOk()
            ->assertSee('Entrar · Lumeron')
            ->assertSee('images/lumeron/logo.png',false)
            ->assertSee('images/lumeron/elephant.png',false)
            ->assertDontSee('class="login-brand-symbol"',false);
    }

    public function test_authenticated_layout_has_lumeron_brand_and_favicon(): void
    {
        $user=User::query()->create([
            'name'=>'Administrador',
            'email'=>'brand-test@example.com',
            'password'=>'SenhaTesteSegura123',
            'role'=>'admin',
            'is_active'=>true,
        ]);
        $this->actingAs($user)->get('/dashboard')->assertOk()
            ->assertSee('images/lumeron/logo.png',false)
            ->assertSee('images/lumeron/elephant.png',false)
            ->assertSee('Lumeron')
            ->assertDontSee('>NEXTOR</strong>',false);
    }

    public function test_new_api_brand_and_old_alias_remain_available(): void
    {
        foreach (['/api/lumeron/products','/api/nextor/products'] as $uri) {
            $route=Route::getRoutes()->match(Request::create($uri,'GET'));
            self::assertStringContainsString('ApiController@products',$route->getActionName());
        }
    }

    public function test_pdv_inherits_the_same_lumeron_shell_as_sales(): void
    {
        $layout=file_get_contents(resource_path('views/layouts/pdv.blade.php'));
        self::assertStringContainsString("@extends('layouts.app')",$layout);
        self::assertStringContainsString("@section('title','PDV')",$layout);
        self::assertStringContainsString('css/pdv-lumeron.css',$layout);
        self::assertStringContainsString('id="pdvOperations"',$layout);
        self::assertStringContainsString('id="pdvFullscreen"',$layout);
        self::assertStringNotContainsString('NEXTOR PDV',$layout);

        $user=User::query()->create([
            'name'=>'Operador de caixa',
            'email'=>'pdv-brand@example.com',
            'password'=>'SenhaTesteSegura123',
            'role'=>'admin',
            'is_active'=>true,
        ]);

        $this->actingAs($user)->get(route('pdv.index'))->assertOk()
            ->assertSee('images/lumeron/logo.png',false)
            ->assertSee('class="cms-topbar"',false)
            ->assertSee('class="cms-sidebar"',false)
            ->assertSee('class="pdv-screen"',false)
            ->assertSee('class="pdv-workbar"',false)
            ->assertSee('id="pdvApp"',false)
            ->assertSee('id="pdvCart"',false)
            ->assertSee('id="pdvSearch"',false)
            ->assertSee('id="pdvFinish"',false)
            ->assertSee('css/pdv-lumeron.css',false);
    }
}
