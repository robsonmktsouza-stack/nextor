<?php

namespace Tests\Feature;

use App\Models\FiscalFcpRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class FiscalFcpRulePersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_fcp_table_stores_uf_ncm_own_flag_rate_and_validity(): void
    {
        self::assertTrue(Schema::hasTable('fiscal_fcp_rules'));
        $rule=FiscalFcpRule::query()->create([
            'uf'=>'BA',
            'ncm_prefix'=>'6913',
            'rate'=>'2.0000',
            'apply_to_own_fcp'=>true,
            'is_active'=>false,
            'valid_from'=>'2026-10-08',
            'notes'=>'Somente exemplo sem valor fiscal.',
        ]);

        $saved=$rule->fresh();
        self::assertSame('BA',$saved->uf);
        self::assertSame('6913',$saved->ncm_prefix);
        self::assertSame('2.0000',$saved->rate);
        self::assertTrue($saved->apply_to_own_fcp);
        self::assertFalse($saved->is_active);
        self::assertSame('2026-10-08',$saved->valid_from->toDateString());
    }
}
