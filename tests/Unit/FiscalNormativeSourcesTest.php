<?php

namespace Tests\Unit;

use App\Services\Fiscal\FiscalNormativeSources;
use PHPUnit\Framework\TestCase;

final class FiscalNormativeSourcesTest extends TestCase
{
    public function test_all_sources_are_https_and_have_scope(): void
    {
        foreach (FiscalNormativeSources::catalog() as $source) {
            $this->assertStringStartsWith('https://', $source['url']);
            $this->assertNotEmpty($source['scope']);
        }
    }

    public function test_lookup_returns_only_relevant_official_sources(): void
    {
        $this->assertContains(FiscalNormativeSources::CONFAZ, FiscalNormativeSources::sourcesFor('icms_st'));
        $this->assertContains(FiscalNormativeSources::RTC_CALCULATOR, FiscalNormativeSources::sourcesFor('cbs'));
        $this->assertSame([], FiscalNormativeSources::sourcesFor('unknown'));
    }
}
