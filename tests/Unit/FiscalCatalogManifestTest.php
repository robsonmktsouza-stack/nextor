<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class FiscalCatalogManifestTest extends TestCase
{
    private static array $manifest;

    public static function setUpBeforeClass(): void
    {
        self::$manifest = json_decode(file_get_contents(dirname(__DIR__, 2).'/resources/fiscal/catalog/2026.10.26.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_bundled_references_have_official_sources_and_no_implicit_numeric_taxes(): void
    {
        $sources = array_column(self::$manifest['sources'], null, 'id');
        foreach ($sources as $source) {
            $host = parse_url($source['url'], PHP_URL_HOST);
            self::assertTrue(str_ends_with($host, '.gov.br') || $host === 'www2.camara.leg.br');
            self::assertStringStartsWith('https://', $source['url']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $source['sha256']);
        }
        $identities = [];
        $errors = [];
        foreach (self::$manifest['entries'] as $entry) {
            $source = $sources[$entry['source_id']] ?? null;
            if (!$source || !str_starts_with($source['url'], 'https://')
                || !str_ends_with(parse_url($source['url'], PHP_URL_HOST), '.gov.br')
                || empty($source['reference']) || empty($source['sha256'])
                || $source['consulted_at'] > self::$manifest['consulted_at']) {
                $errors[] = 'Fonte: '.$entry['kind'].'/'.$entry['code'];
            }
            if (!is_string($entry['code']) || self::containsFloat($entry['parameters'])) {
                $errors[] = 'Precisão/código: '.$entry['kind'].'/'.$entry['code'];
            }
            $identities[] = implode('|', [$entry['kind'], $entry['code'], $entry['uf'], $entry['regime'], $entry['valid_from']]);
        }
        self::assertSame([], $errors);
        self::assertCount(count(array_unique($identities)), $identities);
    }

    public function test_ncm_contains_the_complete_downloaded_leaf_snapshot_preserving_dates_and_leading_zeroes(): void
    {
        $codes = $this->codes('ncm');
        self::assertCount(10516, $codes);
        self::assertSame('complete', self::$manifest['coverage']['ncm']['status']);
        self::assertSame(10516, self::$manifest['coverage']['ncm']['count']);
        self::assertArrayHasKey('01012100', $codes);
        self::assertSame('2022-04-01', $codes['01012100']['valid_from']);
        self::assertNull($codes['01012100']['valid_until']);
        self::assertSame('31/12/9999', $codes['01012100']['parameters']['source_end_date']);
        self::assertSame([], array_values(array_filter(array_keys($codes), fn ($code) => !preg_match('/^\d{8}$/', $code))));
    }

    public function test_ambiguous_ncm_leaves_retain_their_original_text_and_official_parent_context(): void
    {
        $codes = $this->codes('ncm');
        $example = $codes['01012900'];
        self::assertSame('-- Outros', $example['parameters']['source_leaf_description']);
        self::assertStringContainsString('Cavalos', $example['description']);
        self::assertStringEndsWith(' — Outros', $example['description']);
        $derived = array_filter($codes, fn ($entry) => $entry['parameters']['description_derived_from_official_hierarchy'] ?? false);
        self::assertCount(2654, $derived);
        $errors = [];
        foreach ($derived as $entry) {
            foreach ($entry['parameters']['source_parent_path'] as $parent) {
                if (!str_starts_with($entry['code'], $parent['code']) || $parent['description'] === '') $errors[] = $entry['code'];
            }
        }
        self::assertSame([], $errors);
    }

    public function test_cfop_noncontributor_sales_and_historical_limits_are_explicit(): void
    {
        $codes = $this->codes('cfop');
        self::assertStringContainsString('não contribuinte', $codes['6107']['description']);
        self::assertStringContainsString('não contribuinte', $codes['6108']['description']);
        self::assertStringContainsString('terceiros', $codes['6108']['description']);
        self::assertNotSame($codes['6102']['description'], $codes['6108']['description']);
        self::assertSame('partial', self::$manifest['coverage']['cfop']['status']);
        foreach (['5101', '5102', '6101', '6102', '6107', '6108', '1202', '5202', '5915', '5916'] as $code) {
            self::assertNull($codes[$code]['valid_from']);
            self::assertFalse($codes[$code]['parameters']['automatic_application']);
            self::assertTrue($codes[$code]['parameters']['requires_current_legal_review']);
            self::assertNotEmpty($codes[$code]['conditions']);
        }
    }

    public function test_cest_is_a_state_scoped_partial_reference_and_never_an_automatic_st_rule(): void
    {
        $codes = $this->codes('cest');
        self::assertCount(207, $codes);
        self::assertSame('partial', self::$manifest['coverage']['cest']['status']);
        self::assertSame('SP', self::$manifest['coverage']['cest']['uf']);
        self::assertSame(['2402'], $codes['0400100']['parameters']['ncm_prefixes']);
        self::assertSame([], array_values(array_filter($codes, fn ($entry) => $entry['uf'] !== 'SP'
            || $entry['valid_from'] !== null || $entry['valid_until'] !== null
            || $entry['parameters']['automatic_application'] !== false)));
        self::assertSame('not_bundled', self::$manifest['coverage']['benefit']['status']);
        self::assertSame([], $this->codes('benefit'));
    }

    public function test_rtc_is_official_reference_with_paired_codes_and_decimal_reductions(): void
    {
        $csts = $this->codes('ibs_cbs_cst');
        $classes = $this->codes('ibs_cbs_class');
        self::assertCount(18, $csts);
        self::assertCount(173, $classes);
        $errors = [];
        foreach ($classes as $code => $entry) {
            $parameters = $entry['parameters'];
            if (!isset($csts[$parameters['cst']]) || substr((string) $code, 0, 3) !== $parameters['cst']
                || !preg_match('/^\d+\.\d{2}$/', $parameters['ibs_reduction'])
                || !preg_match('/^\d+\.\d{2}$/', $parameters['cbs_reduction'])
                || $parameters['automatic_application'] !== false) $errors[] = $code;
        }
        self::assertSame([], $errors);
        self::assertSame('000', $classes['000001']['parameters']['cst']);
        self::assertTrue($classes['000001']['parameters']['IndNfe']);
        $source = array_column(self::$manifest['sources'], null, 'id')['svrs-rtc'];
        self::assertNull($source['version']);
        self::assertSame('2026-10-26', $source['consulted_at']);
    }

    private function codes(string $kind): array
    {
        return array_column(array_values(array_filter(self::$manifest['entries'], fn ($entry) => $entry['kind'] === $kind)), null, 'code');
    }

    private static function containsFloat(array $values): bool
    {
        foreach ($values as $value) {
            if (is_float($value) || (is_array($value) && self::containsFloat($value))) return true;
        }
        return false;
    }
}
