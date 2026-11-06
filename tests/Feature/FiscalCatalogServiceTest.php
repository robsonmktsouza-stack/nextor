<?php

namespace Tests\Feature;

use App\Models\CompanySetting;
use App\Models\FiscalCatalogEntry;
use App\Models\FiscalCatalogRelease;
use App\Models\FiscalTaxGroup;
use App\Models\FiscalTaxRule;
use App\Models\OperationNature;
use App\Services\Fiscal\FiscalCatalogService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class FiscalCatalogServiceTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\WithIsolatedFiscalCatalog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->isolateFiscalCatalog();
    }

    private function manifest(string $version = 'test.1'): array
    {
        return ['schema_version'=>1, 'version'=>$version, 'consulted_at'=>'2026-10-26',
            'sources'=>[['id'=>'confaz', 'url'=>'https://www.confaz.fazenda.gov.br/legislacao/ajustes/1970/AJ_001_70',
                'reference'=>'Convênio SINIEF s/n, de 15/12/1970, Anexo II', 'version'=>null, 'consulted_at'=>'2026-10-26']],
            'coverage'=>['cfop'=>'partial'],
            'entries'=>[['kind'=>'cfop', 'code'=>'5102', 'description'=>'Venda de mercadoria adquirida ou recebida de terceiros',
                'source_id'=>'confaz', 'valid_from'=>null, 'valid_until'=>null, 'uf'=>null, 'regime'=>null,
                'status'=>'reference', 'conditions'=>['Verificar a operação concreta.'], 'parameters'=>[]]]];
    }

    public function test_reference_install_is_idempotent_and_never_creates_or_changes_company_tax_configuration(): void
    {
        $before = [FiscalTaxGroup::query()->get()->toArray(), FiscalTaxRule::query()->count(), OperationNature::query()->count()];
        $catalog = app(FiscalCatalogService::class);
        $first = $catalog->import($this->manifest());
        $again = $catalog->import($this->manifest());
        self::assertSame($first->id, $again->id);
        self::assertSame(1, FiscalCatalogRelease::query()->count());
        self::assertSame(1, FiscalCatalogEntry::query()->count());
        self::assertSame(64, strlen($first->sha256));
        self::assertSame($before, [FiscalTaxGroup::query()->get()->toArray(), FiscalTaxRule::query()->count(), OperationNature::query()->count()]);
    }

    public function test_same_version_with_changed_content_is_refused_without_overwriting_history(): void
    {
        $catalog = app(FiscalCatalogService::class);
        $release = $catalog->import($this->manifest());
        $changed = $this->manifest();
        $changed['entries'][0]['description'] = 'Conteúdo alterado';
        try {
            $catalog->import($changed);
            self::fail('Uma versão instalada não pode ser sobrescrita.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('conteúdo diferente', $exception->getMessage());
        }
        self::assertSame($release->sha256, $catalog->current()->sha256);
        self::assertSame('Venda de mercadoria adquirida ou recebida de terceiros', FiscalCatalogEntry::query()->sole()->description);
    }

    public function test_new_release_can_revoke_reference_without_deleting_old_evidence(): void
    {
        $catalog = app(FiscalCatalogService::class);
        $old = $catalog->import($this->manifest());
        $manifest = $this->manifest('test.2');
        $manifest['entries'][0]['status'] = 'revoked';
        $new = $catalog->import($manifest);
        self::assertSame($new->id, $catalog->current()->id);
        self::assertSame(0, $catalog->entries('cfop')->count());
        self::assertSame('reference', $old->entries()->sole()->status);
        self::assertSame(2, FiscalCatalogEntry::query()->count());
        self::assertSame($old->version, $old->entries()->sole()->trace()['release']);
    }

    public function test_partial_update_cannot_silently_remove_previous_code(): void
    {
        $catalog = app(FiscalCatalogService::class);
        $catalog->import($this->manifest());
        $manifest = $this->manifest('test.2');
        $manifest['entries'][0]['code'] = '6102';
        try {
            $catalog->import($manifest);
            self::fail('Atualização incompleta aceita.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('catálogo completo', $exception->getMessage());
        }
        self::assertSame(1, FiscalCatalogRelease::query()->count());
    }

    public function test_queries_enforce_dates_state_and_reference_status_without_enabling_templates(): void
    {
        $manifest = $this->manifest();
        $manifest['entries'][0]['valid_from'] = '2026-01-01';
        $manifest['entries'][0]['valid_until'] = '2026-12-31';
        $manifest['entries'][0]['uf'] = 'BA';
        $template = $manifest['entries'][0];
        $template['code'] = '6102'; $template['status'] = 'template';
        $manifest['entries'][] = $template;
        $catalog = app(FiscalCatalogService::class);
        $catalog->import($manifest);
        self::assertSame(1, $catalog->entries('cfop', '2026-01-01', 'BA')->count());
        self::assertSame(1, $catalog->entries('cfop', '2026-12-31', 'BA')->count());
        self::assertSame(0, $catalog->entries('cfop', '2025-12-31', 'BA')->count());
        self::assertSame(0, $catalog->entries('cfop', '2027-01-01', 'BA')->count());
        self::assertSame(0, $catalog->entries('cfop', '2026-10-26', 'SP')->count());
    }

    public function test_duplicate_scope_or_overlapping_validity_is_rejected_before_any_insert(): void
    {
        $manifest = $this->manifest();
        $other = $manifest['entries'][0];
        $other['valid_from'] = '2026-01-01';
        $manifest['entries'][] = $other;
        $this->expectException(ValidationException::class);
        try {
            app(FiscalCatalogService::class)->import($manifest);
        } finally {
            self::assertSame(0, FiscalCatalogRelease::query()->count());
        }
    }

    public function test_non_official_source_is_rejected_without_any_catalog_write(): void
    {
        $manifest = $this->manifest();
        $manifest['sources'][0]['url'] = 'https://www.confaz.fazenda.gov.br.example.com/fake';
        $this->expectException(ValidationException::class);
        try { app(FiscalCatalogService::class)->import($manifest); }
        finally { self::assertSame(0, FiscalCatalogRelease::query()->count()); }
    }

    public function test_unknown_source_and_impossible_date_are_rejected(): void
    {
        $catalog = app(FiscalCatalogService::class);
        foreach (['source', 'date'] as $case) {
            $manifest = $this->manifest();
            if ($case === 'source') $manifest['entries'][0]['source_id'] = 'missing';
            else $manifest['entries'][0]['valid_from'] = '2026-02-30';
            try { $catalog->import($manifest); self::fail('Entrada inválida aceita.'); }
            catch (ValidationException) { self::assertSame(0, FiscalCatalogRelease::query()->count()); }
        }
    }

    public function test_decimal_parameters_preserve_exact_strings_and_reject_floats(): void
    {
        $manifest = $this->manifest();
        // Precision fixture, not a production tax rate or automatic treatment.
        $manifest['entries'][0]['parameters'] = ['decimal_precision_fixture'=>'1.23456789'];
        $catalog = app(FiscalCatalogService::class);
        $release = $catalog->import($manifest);
        self::assertSame('1.23456789', $release->entries()->sole()->parameters['decimal_precision_fixture']);
        $manifest['version'] = 'test.2';
        $manifest['entries'][0]['parameters']['decimal_precision_fixture'] = 1.23456789;
        $this->expectException(ValidationException::class);
        $catalog->import($manifest);
    }

    public function test_company_specific_manifest_cannot_cross_the_installation_boundary(): void
    {
        config()->set('instance.cnpj', '61288313000181');
        CompanySetting::current()->update(['document'=>'61288313000181']);
        $manifest = $this->manifest();
        $manifest['target_cnpj'] = '39323356000100';
        $this->expectException(ValidationException::class);
        app(FiscalCatalogService::class)->import($manifest);
    }

    public function test_matching_company_specific_manifest_can_be_installed_but_does_not_create_rules(): void
    {
        config()->set('instance.cnpj', '61288313000181');
        CompanySetting::current()->update(['document'=>'61288313000181']);
        $manifest = $this->manifest(); $manifest['target_cnpj'] = '61288313000181';
        app(FiscalCatalogService::class)->import($manifest);
        self::assertSame(1, FiscalCatalogRelease::query()->count());
        self::assertSame(0, FiscalTaxRule::query()->count());
    }

    public function test_relational_constraint_prevents_deleting_release_with_entries(): void
    {
        $release = app(FiscalCatalogService::class)->import($this->manifest());
        self::assertTrue(Schema::hasColumn('fiscal_catalog_entries', 'release_id'));
        $this->expectException(QueryException::class);
        DB::table('fiscal_catalog_releases')->where('id', $release->id)->delete();
    }

    public function test_unique_entry_identity_is_enforced_by_the_database(): void
    {
        app(FiscalCatalogService::class)->import($this->manifest());
        $entry = FiscalCatalogEntry::query()->sole()->getAttributes(); unset($entry['id']);
        $this->expectException(QueryException::class);
        DB::table('fiscal_catalog_entries')->insert($entry);
    }

    public function test_installed_reference_cannot_be_edited_through_model(): void
    {
        $release = app(FiscalCatalogService::class)->import($this->manifest());
        $this->expectException(\LogicException::class);
        $release->entries()->sole()->update(['description'=>'Mudança silenciosa']);
    }

    public function test_file_hash_is_checked_before_json_import(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'catalog-test-');
        file_put_contents($path, json_encode($this->manifest(), JSON_THROW_ON_ERROR));
        try {
            app(FiscalCatalogService::class)->importFile($path, str_repeat('0', 64));
            self::fail('Hash divergente aceito.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('SHA-256', $exception->getMessage());
            self::assertSame(0, FiscalCatalogRelease::query()->count());
        } finally { unlink($path); }
    }

    public function test_cli_requires_reviewed_file_hash_and_never_accepts_ambiguous_install_actions(): void
    {
        $this->artisan('fiscal:catalog', ['--install'=>true, '--import'=>'anything'])->assertExitCode(2);
        $this->artisan('fiscal:catalog', ['--import'=>'anything'])->assertExitCode(2);
        $this->artisan('fiscal:catalog', ['--templates'=>true])->assertExitCode(2);
        self::assertSame(0, FiscalCatalogRelease::query()->count());
    }

    public function test_cli_status_reports_no_installed_reference_without_mutating_database(): void
    {
        $this->artisan('fiscal:catalog', ['--json'=>true])->expectsOutputToContain('"version": null')->assertSuccessful();
        self::assertSame(0, FiscalCatalogRelease::query()->count());
    }

    public function test_real_bundled_snapshot_and_explicit_seeder_are_installable_and_idempotent(): void
    {
        $this->seed(\Database\Seeders\FiscalCatalogSeeder::class);
        $catalog = app(FiscalCatalogService::class);
        $release = $catalog->current();
        $manifest = json_decode(file_get_contents($catalog->bundledPath()), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('2026.10.26.1', $release->version);
        self::assertSame(count($manifest['entries']), $release->entry_count);
        self::assertSame(10516, $release->entries()->where('kind', 'ncm')->count());
        self::assertSame('01012100', $release->entries()->where('kind', 'ncm')->where('code', '01012100')->sole()->code);
        self::assertGreaterThan(1000, $catalog->entries('cest', '2026-10-26', 'BA')->count());
        self::assertTrue($catalog->coverage('cest', 'SP', '2026-10-26')['complete']);
        self::assertSame('complete', $release->coverage['cfop']['status']);
        self::assertSame('partial', $release->coverage['benefit']['status']);
        $before = [FiscalCatalogEntry::query()->count(), FiscalTaxGroup::query()->count(), OperationNature::query()->count()];
        $this->seed(\Database\Seeders\FiscalCatalogSeeder::class);
        self::assertSame($before, [FiscalCatalogEntry::query()->count(), FiscalTaxGroup::query()->count(), OperationNature::query()->count()]);
        self::assertSame(1, FiscalCatalogRelease::query()->count());
        self::assertSame(0, FiscalTaxRule::query()->count());
        self::assertSame(0, OperationNature::query()->whereNotNull('catalog_version')->where('is_active', true)->count());
    }

    public function test_new_bundled_edition_preserves_the_previous_immutable_release_and_state_references(): void
    {
        $catalog = app(FiscalCatalogService::class);
        $old = $catalog->importFile(resource_path('fiscal/catalog/2026.10.26.json'));
        $hash = $old->sha256;
        self::assertSame('33d89254c90bda4ff6e0855b374abb85570730c0b729d6fafff6c2f68dfa14ac', $hash);
        self::assertSame(207, $old->entries()->where('kind','cest')->where('uf','SP')->where('status','reference')->count());
        $new = $catalog->installBundled();
        self::assertSame('2026.10.26.1', $new->version);
        self::assertSame($new->id, $catalog->installBundled()->id);
        self::assertSame(2, FiscalCatalogRelease::query()->count());
        self::assertSame($hash, $old->fresh()->sha256);
        self::assertSame(207, $old->entries()->where('kind','cest')->where('uf','SP')->where('status','reference')->count());
        self::assertTrue($new->entries()->where('kind','cest')->whereNull('uf')->where('status','reference')->exists());
    }

    public function test_reference_rows_cannot_hide_behind_not_bundled_coverage(): void
    {
        $manifest = $this->manifest();
        $manifest['coverage']['cfop'] = ['status'=>'not_bundled'];
        try {
            app(FiscalCatalogService::class)->import($manifest);
            self::fail('Cobertura contraditória foi aceita.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('contradiz', $exception->getMessage());
            self::assertSame(0, FiscalCatalogRelease::query()->count());
        }
    }

    public function test_streaming_hash_matches_the_original_canonical_json_for_all_supported_key_and_value_shapes(): void
    {
        $manifest = $this->manifest();
        $manifest['digest_fixture'] = [
            'z_last'=>null,
            'unicode'=>'ação, São Paulo, ☃',
            'slashes'=>'https://www.confaz.fazenda.gov.br/legislacao/convenios/',
            'escaped'=>"Aspas \" barra \\ quebra\n tab\t",
            'sparse_keys'=>[7=>'sete', 2=>'dois', '01'=>'zero-um', 'z'=>false],
            'reordered_list'=>[1=>'segundo', 0=>'primeiro'],
            'mixed_numeric_keys'=>['10'=>'dez', '2'=>'dois', '1.5'=>'fração', '01'=>'texto'],
            'a_first'=>[true, false, 0, 1, null, [], ['b'=>2, 'a'=>1]],
        ];
        $originalCanonical = function (array $value) use (&$originalCanonical): array {
            if (!array_is_list($value)) ksort($value);
            foreach ($value as &$item) if (is_array($item)) $item = $originalCanonical($item);
            return $value;
        };
        $expected = hash('sha256', json_encode($originalCanonical($manifest), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $service = app(FiscalCatalogService::class);
        $release = $service->import($manifest);
        self::assertSame($expected, $release->sha256);
        self::assertSame($release->id, $service->import($manifest)->id);
    }
}
