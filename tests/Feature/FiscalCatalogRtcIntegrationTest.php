<?php

namespace Tests\Feature;

use App\Services\Fiscal\FiscalCatalogService;
use App\Services\Fiscal\Nfe55RtcClassificationCatalog;
use App\Services\Fiscal\Nfe55RtcConfiguredCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class FiscalCatalogRtcIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /** Small, exact subset of the real official snapshot; no inferred flags or rates. */
    private function manifest(string $version = 'rtc.test.1'): array
    {
        $bundle = json_decode(file_get_contents(resource_path('fiscal/catalog/2026.10.26.json')), true, 512, JSON_THROW_ON_ERROR);
        return ['schema_version'=>1, 'version'=>$version, 'consulted_at'=>$bundle['consulted_at'],
            'sources'=>array_values(array_filter($bundle['sources'], fn (array $source) => $source['id'] === 'svrs-rtc')),
            'coverage'=>['ibs_cbs_cst'=>'partial', 'ibs_cbs_class'=>'partial'],
            'entries'=>array_values(array_filter($bundle['entries'], fn (array $entry) =>
                ($entry['kind'] === 'ibs_cbs_cst' && in_array($entry['code'], ['000','200'], true))
                || ($entry['kind'] === 'ibs_cbs_class' && in_array($entry['code'], ['000001','200003','200033'], true))))];
    }

    private function change(array &$manifest, string $kind, string $code, callable $change): void
    {
        foreach ($manifest['entries'] as &$entry) {
            if ($entry['kind'] === $kind && $entry['code'] === $code) $change($entry);
        }
    }

    public function test_installed_rtc_reference_drives_actual_calculator_and_keeps_official_flags_and_reductions(): void
    {
        $release = app(FiscalCatalogService::class)->import($this->manifest());
        $catalog = new Nfe55RtcClassificationCatalog();
        self::assertSame($release->version, $catalog->metadata()['catalog_version']);
        self::assertSame($release->sha256, $catalog->metadata()['catalog_sha256']);
        $profile = $catalog->validate('000', '000001', '2026-10-26');
        self::assertTrue($profile['cst_flags']['IndExigeTrib']);
        self::assertSame($release->version, $profile['catalog_reference']['release']);
        self::assertSame($release->sha256, $profile['catalog_sha256']);

        // Explicit arithmetic inputs are fixtures, never rates supplied by the catalog.
        $tax = ['ibs_cbs_cst'=>'000', 'ibs_cbs_class'=>'000001',
            'ibs_uf_rate'=>'0.1000', 'ibs_municipal_rate'=>'0.0000', 'cbs_rate'=>'0.9000'];
        $calculator = new Nfe55RtcConfiguredCalculator($catalog);
        $integral = $calculator->calculate($tax, 10000, '2026-10-26');
        self::assertSame(10, $integral['totals']['uf']);
        self::assertSame(90, $integral['totals']['cbs']);
        $tax['ibs_cbs_cst'] = '200'; $tax['ibs_cbs_class'] = '200003';
        $tax += ['ibs_uf_reduction_rate'=>'100.00', 'ibs_municipal_reduction_rate'=>'100.00', 'cbs_reduction_rate'=>'100.00'];
        self::assertSame(0, $calculator->calculate($tax, 10000, '2026-10-26')['totals']['cbs']);
        $tax['ibs_cbs_class'] = '200033';
        foreach (['ibs_uf_reduction_rate', 'ibs_municipal_reduction_rate', 'cbs_reduction_rate'] as $field) $tax[$field] = '60.00';
        $reduced = $calculator->calculate($tax, 10000, '2026-10-26');
        self::assertSame(4, $reduced['totals']['uf']);
        self::assertSame(36, $reduced['totals']['cbs']);
    }

    public function test_existing_worker_catalog_object_observes_a_new_import_and_revocation_without_static_database_cache(): void
    {
        $catalog = new Nfe55RtcClassificationCatalog();
        $service = app(FiscalCatalogService::class);
        $first = $service->import($this->manifest());
        self::assertSame($first->sha256, $catalog->validate('000', '000001', '2026-10-26')['catalog_sha256']);
        $next = $this->manifest('rtc.test.2');
        $this->change($next, 'ibs_cbs_class', '000001', function (array &$entry): void { $entry['status'] = 'revoked'; });
        $second = $service->import($next);
        self::assertSame($second->sha256, $catalog->metadata()['catalog_sha256']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('par CST/classificação');
        $catalog->validate('000', '000001', '2026-10-26');
    }

    public function test_new_reference_flag_revision_is_used_by_the_same_real_calculator_instance(): void
    {
        $service = app(FiscalCatalogService::class);
        $service->import($this->manifest());
        $calculator = new Nfe55RtcConfiguredCalculator();
        $tax = ['ibs_cbs_cst'=>'000', 'ibs_cbs_class'=>'000001',
            'ibs_uf_rate'=>'0.1000', 'ibs_municipal_rate'=>'0.0000', 'cbs_rate'=>'0.9000'];
        self::assertSame(90, $calculator->calculate($tax, 10000, '2026-10-26')['totals']['cbs']);
        $next = $this->manifest('rtc.test.2');
        // Synthetic revision proves the live document-model gate, not a legal amendment.
        $this->change($next, 'ibs_cbs_class', '000001', function (array &$entry): void { $entry['parameters']['IndNfe'] = false; });
        $service->import($next);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('não permitida para NF-e');
        $calculator->calculate($tax, 10000, '2026-10-26');
    }

    public function test_missing_installed_classification_does_not_fall_back_to_a_known_bundled_code(): void
    {
        app(FiscalCatalogService::class)->import($this->manifest());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('par CST/classificação');
        (new Nfe55RtcClassificationCatalog())->validate('000', '000005', '2026-10-26');
    }

    public function test_installed_cst_and_class_must_form_the_exact_pair(): void
    {
        app(FiscalCatalogService::class)->import($this->manifest());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('par CST/classificação');
        (new Nfe55RtcClassificationCatalog())->validate('200', '000001', '2026-10-26');
    }

    public function test_missing_official_group_flag_is_never_converted_to_false(): void
    {
        $manifest = $this->manifest();
        $this->change($manifest, 'ibs_cbs_cst', '000', function (array &$entry): void { unset($entry['parameters']['IndDiferimento']); });
        app(FiscalCatalogService::class)->import($manifest);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('IndDiferimento ausente');
        (new Nfe55RtcClassificationCatalog())->validate('000', '000001', '2026-10-26');
    }

    public function test_incomplete_rtc_release_blocks_instead_of_supplying_the_other_kind_from_static_fallback(): void
    {
        $manifest = $this->manifest();
        $manifest['coverage']['ibs_cbs_class'] = 'not_bundled';
        $manifest['entries'] = array_values(array_filter($manifest['entries'], fn ($entry) => $entry['kind'] === 'ibs_cbs_cst'));
        app(FiscalCatalogService::class)->import($manifest);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('instalado incompleto');
        (new Nfe55RtcClassificationCatalog())->validate('000', '000001', '2026-10-26');
    }

    public function test_generic_inclusive_entry_end_and_original_exclusive_rtc_end_do_not_exclude_last_day_twice(): void
    {
        $manifest = $this->manifest();
        $this->change($manifest, 'ibs_cbs_class', '000001', function (array &$entry): void {
            $entry['valid_until'] = '2026-10-25';
            $entry['parameters']['source_valid_until'] = '2026-10-26T00:00:00';
        });
        app(FiscalCatalogService::class)->import($manifest);
        $catalog = new Nfe55RtcClassificationCatalog();
        self::assertSame('000001', $catalog->validate('000', '000001', '2026-10-25')['classification']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('classificação fora da vigência');
        $catalog->validate('000', '000001', '2026-10-26');
    }

    public function test_multiple_non_overlapping_revisions_of_a_code_are_selected_by_issue_date(): void
    {
        $manifest = $this->manifest();
        $this->change($manifest, 'ibs_cbs_class', '000001', function (array &$entry): void { $entry['valid_until'] = '2026-10-24'; });
        $next = array_values(array_filter($manifest['entries'], fn ($entry) => $entry['kind'] === 'ibs_cbs_class' && $entry['code'] === '000001'))[0];
        $next['valid_from'] = '2026-10-25'; $next['valid_until'] = null;
        $next['description'] = 'Fixture de revisão de vigência';
        $manifest['entries'][] = $next;
        app(FiscalCatalogService::class)->import($manifest);
        $catalog = new Nfe55RtcClassificationCatalog();
        self::assertStringContainsString('Situações tributadas', $catalog->validate('000', '000001', '2026-10-24')['description']);
        self::assertSame('Fixture de revisão de vigência', $catalog->validate('000', '000001', '2026-10-25')['description']);
    }

    public function test_ncm_only_reference_import_preserves_the_existing_rtc_snapshot(): void
    {
        $manifest = $this->manifest();
        $manifest['coverage'] = ['ncm'=>'partial', 'ibs_cbs_cst'=>'not_bundled', 'ibs_cbs_class'=>'not_bundled'];
        $manifest['entries'] = [['kind'=>'ncm', 'code'=>'01012100', 'description'=>'Fixture de nomenclatura',
            'source_id'=>'svrs-rtc', 'valid_from'=>'2022-04-01', 'parameters'=>[]]];
        app(FiscalCatalogService::class)->import($manifest);
        $catalog = new Nfe55RtcClassificationCatalog();
        self::assertArrayNotHasKey('catalog_version', $catalog->metadata());
        self::assertSame('000001', $catalog->validate('000', '000001', '2026-10-26')['classification']);
    }

    public function test_explicit_constructor_dataset_remains_independent_of_an_incomplete_installed_release(): void
    {
        $dataset = json_decode(file_get_contents(resource_path('fiscal/rtc-classifications.json')), true, 512, JSON_THROW_ON_ERROR);
        $manifest = $this->manifest();
        $manifest['coverage']['ibs_cbs_class'] = 'not_bundled';
        $manifest['entries'] = array_values(array_filter($manifest['entries'], fn ($entry) => $entry['kind'] === 'ibs_cbs_cst'));
        app(FiscalCatalogService::class)->import($manifest);
        self::assertSame('000001', (new Nfe55RtcClassificationCatalog($dataset))->validate('000', '000001', '2026-10-26')['classification']);
    }

    public function test_every_bundled_rtc_classification_keeps_the_same_official_domain_and_flags_after_installation(): void
    {
        $bundle = json_decode(file_get_contents(resource_path('fiscal/catalog/2026.10.26.json')), true, 512, JSON_THROW_ON_ERROR);
        $manifest = $this->manifest();
        $manifest['entries'] = array_values(array_filter($bundle['entries'], fn ($entry) => in_array($entry['kind'], ['ibs_cbs_cst','ibs_cbs_class'], true)));
        app(FiscalCatalogService::class)->import($manifest);
        $staticDataset = json_decode(file_get_contents(resource_path('fiscal/rtc-classifications.json')), true, 512, JSON_THROW_ON_ERROR);
        $before = new Nfe55RtcClassificationCatalog($staticDataset);
        $installed = new Nfe55RtcClassificationCatalog();
        foreach ($staticDataset['classifications'] as $code => $record) {
            $code = (string) $code;
            try {
                $expected = $before->validate($record['cst'], $code, '2026-10-26');
            } catch (RuntimeException) {
                try {
                    $installed->validate($record['cst'], $code, '2026-10-26');
                    self::fail('Classificação antes bloqueada passou a ser aceita: '.$code);
                } catch (RuntimeException) {
                    self::addToAssertionCount(1);
                }
                continue;
            }
            $actual = $installed->validate($record['cst'], $code, '2026-10-26');
            foreach (['cst', 'flags', 'cst_flags', 'ind_nfe', 'ibs_reduction', 'cbs_reduction', 'rate_type'] as $field) {
                self::assertSame($expected[$field], $actual[$field], 'Perfil oficial divergente: '.$code.' '.$field);
            }
            self::assertSame($expected['legal_reference']['url'], $actual['legal_reference']['url']);
        }
    }
}
