<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\LicenseEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class UnifiedAdminGridTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'Administrador',
            'email' => 'admin-grid@example.test',
            'password' => 'senha-teste-admin-longa',
        ]);
    }

    private function company(int $index = 1): Company
    {
        return Company::create([
            'instance_id' => (string) Str::uuid(),
            'legal_name' => 'CLIENTE GRID '.$index.' LTDA',
            'cnpj' => str_pad((string) $index, 14, '0', STR_PAD_LEFT),
            'email' => 'grid'.$index.'@example.test',
            'subdomain' => 'grid-cliente-'.$index,
            'plan' => 'standard',
            'license_status' => 'active',
            'expires_at' => today()->addDays(7)->toDateString(),
            'modules' => ['products'],
            'license_secret' => bin2hex(random_bytes(48)),
        ]);
    }

    public function test_every_admin_table_uses_shared_grid_toolbar_and_scoped_export(): void
    {
        $this->actingAs($this->admin());
        $company = $this->company();
        $company->record('test', 'Teste do histórico');

        foreach ([
            '/dashboard' => 1,
            '/clients' => 1,
            '/licenses' => 1,
            '/plans' => 1,
            '/invoices' => 1,
            '/history' => 1,
            '/automations' => 3,
        ] as $url => $expectedTables) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertSame($expectedTables, substr_count($html, 'data-admin-list'), $url);
            $this->assertSame($expectedTables, substr_count($html, 'data-grid-table'), $url);
            $this->assertSame($expectedTables, substr_count($html, 'data-grid-toolbar'), $url);
            $this->assertSame($expectedTables, substr_count($html, 'data-grid-export'), $url);
            $this->assertSame($expectedTables, substr_count($html, 'data-grid-print'), $url);
            $this->assertStringNotContainsString('data-row-select', $html);
        }
    }

    public function test_paginated_lists_use_same_page_sizes_and_keep_filters(): void
    {
        $this->actingAs($this->admin());
        for ($i = 1; $i <= 23; $i++) {
            $this->company($i);
        }
        $this->get('/clients?per_page=20&status=active')->assertOk()
            ->assertSee('20 linhas por página')
            ->assertSee('23 clientes');
        $this->get('/licenses?per_page=50&status=active')->assertOk()
            ->assertSee('50 linhas por página')
            ->assertSee('23 licenças');
        $this->get('/plans?per_page=50&q=Padr')->assertOk()
            ->assertSee('Padrão')
            ->assertDontSee('Completo');
        $this->get('/history?per_page=100')->assertOk()
            ->assertSee('100 linhas por página');
        $this->get('/automations?per_page=50')->assertOk()
            ->assertSee('50 linhas por página')
            ->assertSee('data-grid-filename="automacoes-avisos"', false);
    }
}
