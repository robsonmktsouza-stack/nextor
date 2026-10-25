<?php

namespace Tests\Unit;

use App\Services\Fiscal\Nfe55TaxParameters;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class Nfe55TaxParametersTest extends TestCase
{
    public function test_inbound_values_and_legacy_aliases_are_normalized_without_mutating_saved_data(): void
    {
        $saved=['icms_cst'=>'00','icms_cst_inbound'=>'20','icms_deferral_rate'=>'30',
            'pis_calc_type'=>'percentage','mod_bc'=>'3','mva_rate'=>'40'];
        $tax=Nfe55TaxParameters::forDirection($saved,'inbound');
        self::assertSame('20',$tax['icms_cst']);
        self::assertSame('30',$tax['icms_deferment_rate']);
        self::assertSame('percent',$tax['pis_calculation_type']);
        self::assertSame('3',$tax['icms_base_mode']);
        self::assertSame('40',$tax['icms_st_mva']);
        self::assertSame('00',$saved['icms_cst']);
    }

    public function test_a_zero_valued_cst_is_still_an_unsupported_group_request(): void
    {
        foreach (['is_classification'=>'000','pis_st_cst'=>'00','ibs_presumed_credit_cst'=>'00'] as $key=>$value) {
            try { Nfe55TaxParameters::assertSupported([$key=>$value]); self::fail('Group was discarded.'); }
            catch (RuntimeException $error) { self::assertStringContainsString($key,$error->getMessage()); }
        }
    }

    public function test_nonzero_unsupported_groups_cannot_be_silently_omitted(): void
    {
        foreach (['icms_monophase_amount','pis_st_unknown_rate','cofins_st_unknown_rate','ibs_credit_amount','cbs_unknown_credit','difal_amount'] as $key) {
            try { Nfe55TaxParameters::assertSupported([$key=>'1']); self::fail('Group was discarded.'); }
            catch (RuntimeException $error) { self::assertStringContainsString($key,$error->getMessage()); }
        }
    }
}
