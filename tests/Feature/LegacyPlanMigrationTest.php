<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class LegacyPlanMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $suffix, ?array $modules): Company
    {
        return Company::create([
            'instance_id' => (string) Str::uuid(),
            'legal_name' => 'EMPRESA '.$suffix,
            'cnpj' => str_pad($suffix, 14, '0', STR_PAD_LEFT),
            'email' => 'company'.$suffix.'@example.test',
            'subdomain' => 'legacy-'.$suffix,
            'plan' => 'standard',
            'license_status' => 'active',
            'expires_at' => today()->addMonth()->toDateString(),
            'modules' => $modules,
            'license_secret' => bin2hex(random_bytes(48)),
        ]);
    }

    public function test_existing_company_with_custom_modules_keeps_access_through_archived_plan(): void
    {
        $custom = $this->company('101', ['finance', 'products', 'pdv', 'services']);
        $ordinary = $this->company('102', ['pdv', 'products']);
        $unset = $this->company('103', null);
        $secret = $custom->license_secret;

        $migration = require database_path('migrations/2026_10_22_000001_preserve_legacy_entitlements_as_plans.php');
        $migration->up();

        $this->assertSame('legacy-'.$custom->id, $custom->fresh()->plan);
        $legacy = Plan::where('code', $custom->fresh()->plan)->firstOrFail();
        $this->assertFalse($legacy->active);
        $this->assertEqualsCanonicalizing(['finance','products','pdv','services'], $custom->fresh()->entitledModules());
        $this->assertSame('standard', $ordinary->fresh()->plan);
        $this->assertSame('standard', $unset->fresh()->plan);
        $this->assertSame($secret, $custom->fresh()->license_secret);
        $this->assertDatabaseHas('license_events', [
            'company_id' => $custom->id,
            'event_type' => 'plan_migrated',
        ]);
        $migration->up();
        $this->assertDatabaseCount('license_events', 1);
    }

    public function test_selecting_another_plan_changes_modules_without_any_module_checkbox(): void
    {
        $company = $this->company('301', ['pdv', 'products']);
        $admin = \App\Models\User::create([
            'name' => 'Administradora',
            'email' => 'admin-select-plan@example.test',
            'password' => 'senha-longa-de-teste',
        ]);
        $this->actingAs($admin)->post(route('clients.modules', $company), [
            'plan' => 'advanced',
            'expires_at' => today()->addMonth()->toDateString(),
        ])->assertRedirect();

        $company->refresh();
        $this->assertSame('advanced', $company->plan);
        $this->assertNull($company->modules);
        $this->assertSame(['pdv','products','finance'], $company->entitledModules());
        $this->assertDatabaseHas('license_events', [
            'company_id' => $company->id,
            'event_type' => 'license_changed',
        ]);
    }

    public function test_license_endpoint_does_not_allow_explicit_module_override(): void
    {
        $company = $this->company('201', ['pdv','products']);
        $admin = \App\Models\User::create([
            'name'=>'Administrador', 'email'=>'operator-plan@example.test', 'password'=>'senha-longateste',
        ]);
        $this->actingAs($admin)->post(route('clients.modules', $company), [
            'plan' => 'standard',
            'modules' => ['finance'],
        ])->assertSessionHasErrors('modules');

        $this->actingAs($admin)->post('/clients', [
            'legal_name' => 'EMPRESA NOVA LTDA',
            'cnpj' => '39.323.356/0001-00',
            'email' => 'new@example.test',
            'subdomain' => 'nova-nova',
            'plan' => 'standard',
            'modules' => ['finance'],
        ])->assertSessionHasErrors('modules');
    }
}
