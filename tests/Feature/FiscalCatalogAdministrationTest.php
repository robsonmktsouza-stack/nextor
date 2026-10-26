<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\CompanySetting;
use App\Models\FiscalCatalogRelease;
use App\Models\FiscalCatalogEntry;
use App\Models\FiscalTaxGroup;
use App\Models\FiscalTaxRule;
use App\Models\OperationNature;
use App\Models\User;
use App\Services\Fiscal\FiscalCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class FiscalCatalogAdministrationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::query()->create(['name' => 'Admin catálogo', 'email' => 'catalog-admin@example.test',
            'password' => 'senhaSegura123', 'role' => 'admin', 'is_active' => true]);
    }

    private function manifest(string $version = 'ui-test-1'): array
    {
        return ['schema_version' => 1, 'version' => $version, 'consulted_at' => now()->toDateString(),
            'sources' => [['id' => 'confaz', 'url' => 'https://www.confaz.fazenda.gov.br/legislacao',
                'reference' => 'Fonte de teste do importador', 'consulted_at' => now()->toDateString(), 'version' => 'teste']],
            'coverage' => ['cfop' => 'partial', 'ncm' => 'partial'],
            'entries' => [
                ['kind' => 'cfop', 'code' => '5102', 'description' => 'Venda de mercadoria — catálogo de teste',
                    'source_id' => 'confaz', 'valid_from' => '2026-01-01', 'conditions' => ['Conferir condições do teste.']],
                ['kind' => 'ncm', 'code' => '01012100', 'description' => 'Reprodutores de raça pura — catálogo de teste',
                    'source_id' => 'confaz', 'valid_from' => '2026-01-01'],
            ]];
    }

    private function upload(array|string $content): array
    {
        $bytes = is_array($content) ? json_encode($content, JSON_THROW_ON_ERROR) : $content;
        return ['catalog_file' => UploadedFile::fake()->createWithContent('catalog.json', $bytes),
            'sha256' => hash('sha256', $bytes)];
    }

    public function test_catalog_requires_authentication_and_settings_permission(): void
    {
        $this->get(route('fiscal.catalog.index'))->assertRedirect(route('login'));
        $user = User::query()->create(['name' => 'Vendedor', 'email' => 'catalog-sales@example.test',
            'password' => 'senhaSegura123', 'role' => 'sales', 'is_active' => true, 'permissions' => ['sales']]);
        $this->actingAs($user)->get(route('fiscal.catalog.index'))->assertForbidden();
        $this->post(route('fiscal.catalog.install'))->assertForbidden();
        $this->post(route('fiscal.catalog.import'))->assertForbidden();
        self::assertSame(0, FiscalCatalogRelease::query()->count());
    }

    public function test_catalog_screen_exposes_existing_configuration_and_filtered_reference_sources(): void
    {
        app(FiscalCatalogService::class)->import($this->manifest());
        $this->actingAs($this->admin())->get(route('fiscal.catalog.index', ['kind' => 'ncm', 'q' => '01012100']))
            ->assertOk()->assertSee('ui-test-1')->assertSee('Reprodutores de raça pura — catálogo de teste')
            ->assertDontSee('Venda de mercadoria — catálogo de teste')
            ->assertSee('Naturezas de operação')->assertSee('Grupos tributários')
            ->assertSee('Regras e parâmetros')->assertSee('Fonte oficial')->assertSee('Histórico e vigência');
    }

    public function test_catalog_filters_date_and_preserves_historical_version_visibility(): void
    {
        $first = app(FiscalCatalogService::class)->import($this->manifest());
        $new = $this->manifest('ui-test-2');
        $new['entries'][0]['description'] = 'Nova descrição do código';
        app(FiscalCatalogService::class)->import($new);
        $this->actingAs($this->admin())->get(route('fiscal.catalog.index', ['release' => $first->id, 'date' => '2025-12-31']))
            ->assertOk()->assertSee('Nenhum registro encontrado');
        $this->get(route('fiscal.catalog.index', ['release' => $first->id, 'date' => '2026-01-01']))
            ->assertOk()->assertSee('Venda de mercadoria — catálogo de teste')->assertDontSee('Nova descrição do código');
        $this->get(route('fiscal.catalog.index', ['tab' => 'history']))->assertOk()
            ->assertSee('ui-test-1')->assertSee('ui-test-2')->assertSee('SHA-256');
    }

    public function test_json_import_is_idempotent_and_does_not_enable_rules_or_change_production_settings(): void
    {
        AppSetting::put('nfe', 'production_enabled', false);
        AppSetting::put('nfe', 'production_next_number', 97);
        $beforeSettings = AppSetting::query()->orderBy('id')->get()->toArray();
        $beforeRules = FiscalTaxRule::query()->count();
        $this->actingAs($this->admin());
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->post(route('fiscal.catalog.import'), $this->upload($this->manifest()))
                ->assertRedirect()->assertSessionHasNoErrors();
        }
        self::assertSame(1, FiscalCatalogRelease::query()->count());
        self::assertSame($beforeRules, FiscalTaxRule::query()->count());
        self::assertSame($beforeSettings, AppSetting::query()->orderBy('id')->get()->toArray());
    }

    public function test_import_rejects_invalid_json_unofficial_source_and_same_version_with_changed_content(): void
    {
        $this->actingAs($this->admin());
        $this->post(route('fiscal.catalog.import'), $this->upload('{broken'))
            ->assertSessionHasErrors('catalog_file');
        $bad = $this->manifest(); $bad['sources'][0]['url'] = 'https://example.com/taxes';
        $this->post(route('fiscal.catalog.import'), $this->upload($bad))
            ->assertSessionHasErrors('catalog');
        self::assertSame(0, FiscalCatalogRelease::query()->count());
        app(FiscalCatalogService::class)->import($this->manifest());
        $changed = $this->manifest(); $changed['entries'][0]['description'] = 'Conteúdo substituído';
        $this->post(route('fiscal.catalog.import'), $this->upload($changed))
            ->assertSessionHasErrors('catalog');
        self::assertSame(1, FiscalCatalogRelease::query()->count());
    }

    public function test_installed_codes_feed_existing_assisted_fields_without_shipping_ncm_table(): void
    {
        app(FiscalCatalogService::class)->import($this->manifest());
        $this->actingAs($this->admin())->getJson(route('reference.fiscal-codes'))
            ->assertOk()->assertJsonPath('version', 'ui-test-1')->assertJsonPath('reference_only', true)
            ->assertJsonPath('catalogs.cfop.5102', 'Venda de mercadoria — catálogo de teste')
            ->assertJsonMissingPath('catalogs.ncm');
    }

    public function test_import_rejects_missing_or_different_approved_file_checksum(): void
    {
        $this->actingAs($this->admin());
        $payload = $this->upload($this->manifest());
        unset($payload['sha256']);
        $this->post(route('fiscal.catalog.import'), $payload)->assertSessionHasErrors('sha256');
        $payload = $this->upload($this->manifest());
        $payload['sha256'] = str_repeat('0', 64);
        $this->post(route('fiscal.catalog.import'), $payload)->assertSessionHasErrors('sha256');
        self::assertSame(0, FiscalCatalogRelease::query()->count());
    }

    public function test_complete_reference_snapshot_replaces_fallback_and_filters_expired_or_other_state_codes(): void
    {
        CompanySetting::current()->update(['state' => 'BA']);
        $manifest = $this->manifest();
        // This deliberately small test snapshot exercises completeness behavior,
        // and is not a claim that these few rows form an official complete table.
        $manifest['coverage']['cfop'] = ['status' => 'complete'];
        $manifest['entries'][] = ['kind' => 'cfop', 'code' => '6102', 'description' => 'Código expirado do teste',
            'source_id' => 'confaz', 'valid_from' => now()->subDays(2)->toDateString(),
            'valid_until' => now()->subDay()->toDateString()];
        $manifest['entries'][] = ['kind' => 'cfop', 'code' => '5405', 'description' => 'Referência exclusiva SP do teste',
            'source_id' => 'confaz', 'uf' => 'SP'];
        app(FiscalCatalogService::class)->import($manifest);
        $this->actingAs($this->admin())->getJson(route('reference.fiscal-codes'))->assertOk()
            ->assertJsonPath('catalogs.cfop.5102', 'Venda de mercadoria — catálogo de teste')
            ->assertJsonMissingPath('catalogs.cfop.5101')
            ->assertJsonMissingPath('catalogs.cfop.6102')
            ->assertJsonMissingPath('catalogs.cfop.5405');

        CompanySetting::current()->update(['state' => 'SP']);
        $this->getJson(route('reference.fiscal-codes'))->assertOk()
            ->assertJsonPath('catalogs.cfop.5405', 'Referência exclusiva SP do teste');
        CompanySetting::current()->update(['state' => null]);
        $this->getJson(route('reference.fiscal-codes'))->assertOk()
            ->assertJsonMissingPath('catalogs.cfop.5405');
    }

    public function test_partial_reference_snapshot_preserves_unknown_fallback_and_removes_known_revoked_code(): void
    {
        $manifest = $this->manifest();
        $manifest['entries'][] = ['kind' => 'cfop', 'code' => '5101', 'description' => 'Revogação simulada no teste',
            'source_id' => 'confaz', 'status' => 'revoked'];
        app(FiscalCatalogService::class)->import($manifest);
        $this->actingAs($this->admin())->getJson(route('reference.fiscal-codes'))->assertOk()
            ->assertJsonPath('catalogs.cfop.5102', 'Venda de mercadoria — catálogo de teste')
            ->assertJsonStructure(['catalogs' => ['cfop' => ['5202']]])
            ->assertJsonMissingPath('catalogs.cfop.5101');
    }

    public function test_bundled_install_adds_official_ncm_and_optional_inactive_models_idempotently_preserving_customizations(): void
    {
        $legacy = FiscalTaxGroup::query()->whereNull('catalog_version')->firstOrFail();
        $legacy->update(['notes' => 'Configuração personalizada da instalação', 'is_default' => true]);
        $legacyIds = FiscalTaxGroup::query()->whereNull('catalog_version')->pluck('id')->all();
        $beforeGroups = DB::table('fiscal_tax_groups')->whereIn('id', $legacyIds)->orderBy('id')->get()->toJson();
        AppSetting::put('nfe', 'production_enabled', false);
        AppSetting::put('nfe', 'production_next_number', 97);
        $beforeSettings = AppSetting::query()->orderBy('id')->get()->toArray();
        $beforeRules = FiscalTaxRule::query()->count();
        $this->actingAs($this->admin());

        // The unchecked option installs references only, without company models.
        $this->post(route('fiscal.catalog.install'))->assertRedirect()->assertSessionHasNoErrors();
        self::assertSame(10516, FiscalCatalogEntry::query()->where('kind', 'ncm')->count());
        self::assertSame(0, OperationNature::query()->whereNotNull('catalog_version')->count());

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->post(route('fiscal.catalog.install'), ['install_templates' => '1'])
                ->assertRedirect()->assertSessionHasNoErrors();
        }
        self::assertSame(1, FiscalCatalogRelease::query()->count());
        self::assertSame('2026.10.26.1', FiscalCatalogRelease::query()->sole()->version);
        self::assertSame(16, OperationNature::query()->where('catalog_version', '2026.10.26.1')->count());
        self::assertSame(13, FiscalTaxGroup::query()->where('catalog_version', '2026.10.26.1')->count());
        self::assertSame(0, OperationNature::query()->whereNotNull('catalog_version')->where('is_active', true)->count());
        self::assertSame(0, FiscalTaxGroup::query()->whereNotNull('catalog_version')->where('is_active', true)->count());
        self::assertSame(0, FiscalTaxGroup::query()->whereNotNull('catalog_version')->where('is_default', true)->count());
        self::assertSame(16, DB::table('fiscal_operation_nature_revisions')->count());
        self::assertSame(13, DB::table('fiscal_tax_group_revisions')->count());
        self::assertSame($beforeGroups, DB::table('fiscal_tax_groups')->whereIn('id', $legacyIds)->orderBy('id')->get()->toJson());
        self::assertSame($beforeSettings, AppSetting::query()->orderBy('id')->get()->toArray());
        self::assertSame($beforeRules, FiscalTaxRule::query()->count());

        $this->get(route('fiscal.catalog.index', ['kind' => 'ncm', 'q' => '01012100']))
            ->assertOk()->assertSee('01012100')->assertSee('Fonte oficial');
        $this->get(route('fiscal.catalog.index', ['tab' => 'history']))->assertOk()
            ->assertSee('2026.10.26')->assertSee('SHA-256 do conteúdo canônico');
    }
}
