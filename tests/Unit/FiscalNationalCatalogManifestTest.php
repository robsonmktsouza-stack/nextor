<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class FiscalNationalCatalogManifestTest extends TestCase
{
    private static array $manifest;
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root=dirname(__DIR__,2);
        self::$manifest=json_decode(file_get_contents(self::$root.'/resources/fiscal/catalog/2026.10.26.1.json'),true,512,JSON_THROW_ON_ERROR);
    }

    public static function tearDownAfterClass(): void
    {
        self::$manifest=[];
    }

    public function test_previous_catalog_remains_byte_identical_and_release_pointer_verifies_new_snapshot(): void
    {
        self::assertSame('8942c3161093b9c2177ed5bdef737257b89f9b252f996855831490b40d5d8b07',
            hash_file('sha256',self::$root.'/resources/fiscal/catalog/2026.10.26.json'));
        $pointer=json_decode(file_get_contents(self::$root.'/resources/fiscal/catalog/current.json'),true,512,JSON_THROW_ON_ERROR);
        self::assertSame('2026.10.26.1',$pointer['version']);
        self::assertSame($pointer['sha256'],hash_file('sha256',self::$root.'/resources/fiscal/catalog/'.$pointer['file']));
        self::assertCount(15986,self::$manifest['entries']);
    }

    public function test_national_cfop_snapshot_contains_every_official_code_and_does_not_enable_forbidden_nfe_codes(): void
    {
        $rows=$this->kind('cfop');
        self::assertCount(619,$rows);
        self::assertCount(619,array_unique(array_column($rows,'code')));
        self::assertSame(585,count(array_filter($rows,fn($r)=>$r['parameters']['ind_nfe']===true)));
        self::assertSame(34,count(array_filter($rows,fn($r)=>$r['parameters']['ind_nfe']===false)));
        self::assertSame([],array_values(array_filter($rows,fn($r)=>$r['uf']!==null
            || $r['parameters']['ind_nfe']!==(bool)$r['parameters']['indNFe'])));
        self::assertSame('complete',self::$manifest['coverage']['cfop']['status']);
        self::assertSame('BR',self::$manifest['coverage']['cfop']['scope']);
    }

    public function test_all_national_cest_annexes_include_revocations_future_effects_and_territorial_exceptions(): void
    {
        $rows=array_values(array_filter($this->kind('cest'),fn($r)=>empty($r['parameters']['legacy_state_reference'])));
        self::assertCount(1058,$rows);
        self::assertCount(1054,array_unique(array_column($rows,'code')));
        $current=array_filter($rows,fn($r)=>$r['uf']===null && $r['status']==='reference'
            && (!$r['valid_from'] || $r['valid_from']<='2026-10-26')
            && (!$r['valid_until'] || $r['valid_until']>='2026-10-26'));
        self::assertCount(1041,array_unique(array_column($current,'code')));
        $future=array_values(array_filter($rows,fn($r)=>$r['valid_from']==='2026-11-01'));
        self::assertCount(5,$future);
        foreach($future as $entry) self::assertContains('SP',$entry['parameters']['excluded_ufs']);
        self::assertCount(2,array_filter($rows,fn($r)=>$r['uf']==='SP'));
        self::assertCount(4,array_filter($rows,fn($r)=>($r['parameters']['requires_manual_ncm_review']??false)===true));
    }

    public function test_state_benefit_research_covers_all_states_without_claiming_missing_tables_are_complete(): void
    {
        $coverage=self::$manifest['coverage']['benefit'];
        self::assertCount(27,$coverage['by_uf']);
        self::assertSame('partial',$coverage['status']);
        self::assertSame('UF',$coverage['scope']);
        self::assertSame('complete',$coverage['by_uf']['SP']['status']);
        self::assertSame('complete',$coverage['by_uf']['SC']['status']);
        self::assertNotSame('complete',$coverage['by_uf']['BA']['status']);
        self::assertNotSame('complete',$coverage['by_uf']['RS']['status']);
        self::assertCount(3310,$this->kind('benefit'));
        $unsafe=array_values(array_filter($this->kind('benefit'),function(array $entry): bool {
            return !$entry['uf'] || (in_array($entry['uf'],['RS','RJ','AC'],true)
                && ($entry['status']!=='template' || ($entry['parameters']['automatic_application']??null)!==false));
        }));
        self::assertSame([],$unsafe);
    }

    public function test_sources_are_official_and_captured_reference_bytes_match_their_recorded_hashes(): void
    {
        $sources=array_column(self::$manifest['sources'],null,'id');
        foreach($sources as $source){
            $host=parse_url($source['url'],PHP_URL_HOST);
            self::assertTrue(str_ends_with($host,'.gov.br') || str_ends_with($host,'.leg.br'));
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/',$source['sha256']);
        }
        foreach(['nfe-cfop-national-20260904'=>'nfe-cfop-2026-09-04.xlsx',
            'confaz-cest-national-142-18'=>'confaz-cest-2026-10-26.txt',
            'confaz-cest-amendment-107-26'=>'confaz-cest-107-2026-10-26.txt'] as $id=>$file){
            self::assertSame($sources[$id]['sha256'],hash_file('sha256',self::$root.'/resources/fiscal/sources/'.$file));
        }
    }

    private function kind(string $kind): array
    {
        return array_values(array_filter(self::$manifest['entries'],fn($entry)=>$entry['kind']===$kind));
    }
}
