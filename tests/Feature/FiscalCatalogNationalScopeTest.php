<?php

namespace Tests\Feature;

use App\Models\FiscalCatalogRelease;
use App\Services\Fiscal\FiscalCatalogCoverage;
use App\Services\Fiscal\FiscalCatalogService;
use App\Services\Fiscal\FiscalProductClassificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class FiscalCatalogNationalScopeTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\WithIsolatedFiscalCatalog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->isolateFiscalCatalog();
    }

    private function manifest(string $version = 'national.fixture.1'): array
    {
        return ['schema_version'=>1, 'version'=>$version, 'consulted_at'=>'2026-10-26',
            'sources'=>[['id'=>'fixture', 'url'=>'https://www.confaz.fazenda.gov.br/legislacao/convenios/2018/CV142_18',
                'reference'=>'Fixture isolada de escopo e atualização; não é publicação normativa.', 'consulted_at'=>'2026-10-26']],
            'coverage'=>['ncm'=>['status'=>'complete', 'scope'=>'BR', 'snapshot_date'=>'2026-10-26'],
                'cest'=>['status'=>'complete', 'scope'=>'BR', 'snapshot_date'=>'2026-10-26'],
                'benefit'=>['status'=>'partial', 'scope'=>'UF', 'by_uf'=>[
                    'ES'=>['status'=>'complete', 'count'=>1, 'snapshot_date'=>'2026-10-26'],
                    'RJ'=>['status'=>'complete', 'count'=>1], 'BA'=>['status'=>'partial', 'count'=>1],
                    'AM'=>['status'=>'unavailable', 'count'=>0],
                ]]],
            'entries'=>[
                ['kind'=>'ncm', 'code'=>'22030000', 'description'=>'Cervejas de malte', 'source_id'=>'fixture'],
                ['kind'=>'ncm', 'code'=>'61091000', 'description'=>'Camisetas de algodão', 'source_id'=>'fixture'],
                ['kind'=>'cest', 'code'=>'0302100', 'description'=>'Domínio nacional de cervejas', 'source_id'=>'fixture',
                    'parameters'=>['ncm_prefixes'=>['2203'], 'ncm_link_validation'=>'prefix']],
                ['kind'=>'cest', 'code'=>'0302100', 'description'=>'Referência estadual histórica preservada', 'source_id'=>'fixture',
                    'uf'=>'SP', 'status'=>'template', 'parameters'=>['ncm_prefixes'=>['6109'], 'legacy_state_reference'=>true]],
                ['kind'=>'benefit', 'code'=>'ES000001', 'description'=>'Fixture de compatibilidade de benefício', 'source_id'=>'fixture',
                    'uf'=>'ES', 'parameters'=>['icms_csts'=>['40'], 'csosns'=>[], 'crts'=>['2','3']]],
                ['kind'=>'benefit', 'code'=>'RJ000001', 'description'=>'Fixture de benefício RJ', 'source_id'=>'fixture',
                    'uf'=>'RJ', 'parameters'=>['icms_csts'=>['20']]],
                ['kind'=>'benefit', 'code'=>'BA000001', 'description'=>'Fixture de benefício BA', 'source_id'=>'fixture',
                    'uf'=>'BA', 'parameters'=>[]],
            ]];
    }

    public function test_national_cest_domain_validates_ba_rj_am_and_sp_without_importing_state_st_treatment(): void
    {
        app(FiscalCatalogService::class)->import($this->manifest());
        $classifications = app(FiscalProductClassificationService::class);
        foreach (['BA','RJ','AM','SP'] as $uf) {
            $codes = ['ncm'=>'22030000', 'cest'=>'0302100'];
            $classifications->assertForEmission($codes, '2026-10-26', $uf);
            $trace = $classifications->trace($codes, '2026-10-26', $uf);
            self::assertNull($trace['entries']['cest']['reference']['uf']);
            self::assertSame(['2203'], $trace['entries']['cest']['reference']['parameters']['ncm_prefixes']);
            self::assertArrayNotHasKey('icms_st_rate', $trace['entries']['cest']['reference']['parameters']);
        }
    }

    public function test_completeness_is_scoped_by_state_and_current_snapshot_never_proves_historical_absence(): void
    {
        $release = app(FiscalCatalogService::class)->import($this->manifest());
        self::assertTrue(FiscalCatalogCoverage::scope($release, 'benefit', 'ES', '2026-10-26')['complete']);
        self::assertTrue(FiscalCatalogCoverage::scope($release, 'benefit', 'RJ')['complete']);
        self::assertFalse(FiscalCatalogCoverage::scope($release, 'benefit', 'BA')['complete']);
        self::assertSame('unavailable', FiscalCatalogCoverage::scope($release, 'benefit', 'AM')['status']);
        self::assertSame('not_bundled', FiscalCatalogCoverage::scope($release, 'benefit', 'AC')['status']);
        self::assertFalse(FiscalCatalogCoverage::scope($release, 'benefit', null)['complete']);
        self::assertFalse(FiscalCatalogCoverage::scope($release, 'benefit', 'ES', '2025-01-01')['complete']);
        self::assertTrue(FiscalCatalogCoverage::scope($release, 'cest', 'BA', '2026-10-26')['complete']);
        self::assertFalse(FiscalCatalogCoverage::scope(null, 'cest', 'BA')['complete']);
    }

    public function test_national_domain_takes_precedence_over_old_state_reference_even_if_it_was_active(): void
    {
        $manifest = $this->manifest();
        $manifest['entries'][3]['status'] = 'reference';
        app(FiscalCatalogService::class)->import($manifest);
        $service = app(FiscalProductClassificationService::class);
        $service->assertForEmission(['ncm'=>'22030000', 'cest'=>'0302100'], '2026-10-26', 'SP');
        self::assertNull($service->trace(['ncm'=>'22030000','cest'=>'0302100'], '2026-10-26', 'SP')['entries']['cest']['reference']['uf']);
    }

    public function test_explicit_uf_exception_and_new_definition_take_effect_on_their_actual_dates(): void
    {
        $manifest = $this->manifest();
        // Synthetic transition fixture tests territorial selection, not a tax law.
        $manifest['entries'][2]['valid_from'] = '2026-01-01';
        $manifest['entries'][2]['valid_until'] = '2026-10-31';
        $future = $manifest['entries'][2];
        $future['valid_from'] = '2026-11-01'; $future['valid_until'] = null;
        $future['parameters']['excluded_ufs'] = ['SP'];
        $manifest['entries'][] = $future;
        $manifest['entries'][3]['status'] = 'reference';
        $manifest['entries'][3]['valid_from'] = '2026-11-01';
        $manifest['entries'][3]['parameters'] = ['ncm_prefixes'=>['2203'], 'territorial_exception'=>true];
        $catalog = app(FiscalCatalogService::class);
        $catalog->import($manifest);
        $products = app(FiscalProductClassificationService::class);
        $codes = ['ncm'=>'22030000','cest'=>'0302100'];
        self::assertNull($products->trace($codes, '2026-10-31', 'SP')['entries']['cest']['reference']['uf']);
        self::assertSame('SP', $products->trace($codes, '2026-11-01', 'SP')['entries']['cest']['reference']['uf']);
        self::assertNull($products->trace($codes, '2026-11-01', 'BA')['entries']['cest']['reference']['uf']);
        self::assertSame(1, $catalog->entries('cest', '2026-11-01', 'SP')->count());
        self::assertSame(1, $catalog->entries('cest', '2026-11-01', 'BA')->count());
        self::assertSame(0, $catalog->entries('cest', '2026-11-01')->count());
        $products->assertForEmission($codes, '2026-11-01', 'SP');
    }

    public function test_snapshot_update_is_idempotent_and_preserves_state_source_in_earlier_release(): void
    {
        $catalog = app(FiscalCatalogService::class);
        $old = $catalog->import($this->manifest());
        $next = $this->manifest('national.fixture.2');
        $next['entries'][3]['description'] = 'Histórico da fonte estadual, sem aplicação automática';
        $new = $catalog->import($next);
        self::assertSame($new->id, $catalog->import($next)->id);
        self::assertSame(2, FiscalCatalogRelease::query()->count());
        self::assertSame('Referência estadual histórica preservada', $old->entries()->where('kind','cest')->where('uf','SP')->sole()->description);
        self::assertSame('template', $new->entries()->where('kind','cest')->where('uf','SP')->sole()->status);
        self::assertTrue($new->entries()->where('kind','cest')->whereNull('uf')->exists());
    }

    public function test_explicitly_unproven_ncm_link_can_be_maintained_but_cannot_drive_automatic_emission(): void
    {
        $manifest = $this->manifest();
        $manifest['entries'][2]['parameters'] = ['ncm_link_validation'=>'manual', 'requires_manual_ncm_review'=>true];
        app(FiscalCatalogService::class)->import($manifest);
        $service = app(FiscalProductClassificationService::class);
        $codes = ['ncm'=>'22030000', 'cest'=>'0302100'];
        self::assertSame($codes, $service->validate($codes, null, '2026-10-26'));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('vínculo NCM deste CEST não está comprovado');
        $service->assertForEmission($codes, '2026-10-26', 'BA');
    }

    public function test_invalid_correspondences_and_territorial_metadata_are_rejected_before_persistence(): void
    {
        foreach (['ncm_prefix','cst','csosn','crt','benefit_uf','excluded_uf','coverage_uf','coverage_date','coverage_scope','national_contradiction'] as $case) {
            $manifest = $this->manifest();
            match ($case) {
                'ncm_prefix' => $manifest['entries'][2]['parameters']['ncm_prefixes'] = ['2A03'],
                'cst' => $manifest['entries'][4]['parameters']['icms_csts'] = ['99'],
                'csosn' => $manifest['entries'][4]['parameters']['csosns'] = [500],
                'crt' => $manifest['entries'][4]['parameters']['crts'] = ['5'],
                'benefit_uf' => $manifest['entries'][4]['uf'] = null,
                'excluded_uf' => $manifest['entries'][2]['parameters']['excluded_ufs'] = ['ZZ'],
                'coverage_uf' => $manifest['coverage']['benefit']['by_uf']['ZZ'] = 'complete',
                'coverage_date' => $manifest['coverage']['benefit']['by_uf']['ES']['snapshot_date'] = '2026-02-30',
                'coverage_scope' => $manifest['coverage']['benefit']['scope'] = 'ALL',
                'national_contradiction' => $manifest['coverage']['benefit'] = ['scope'=>'BR','status'=>'complete','by_uf'=>['BA'=>'partial']],
            };
            try {
                app(FiscalCatalogService::class)->import($manifest);
                self::fail('Metadados inválidos aceitos: '.$case);
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('catalog', $exception->errors(), $case);
                self::assertSame(0, FiscalCatalogRelease::query()->count());
            }
        }
    }
}
