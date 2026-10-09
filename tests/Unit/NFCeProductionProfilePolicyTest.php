<?php

namespace Tests\Unit;

use App\Services\Fiscal\NFCeProductionProfilePolicy;
use PHPUnit\Framework\TestCase;

final class NFCeProductionProfilePolicyTest extends TestCase
{
    public function test_production_profile_must_be_explicitly_approved(): void
    {
        $profile=['cfop_outbound_internal'=>'5102','icms_csosn'=>'102','pis_cst'=>'49','cofins_cst'=>'49'];
        $policy = new NFCeProductionProfilePolicy(['BA:65:1:5102:102:49:49']);
        self::assertSame('BA:65:1:5102:102:49:49', $policy->key('1', $profile));
        self::assertTrue($policy->approved('1', $profile));
        self::assertFalse($policy->approved('1', array_replace($profile,['icms_csosn'=>'103'])));
        self::assertFalse((new NFCeProductionProfilePolicy([]))->approved('1', $profile));
    }

    public function test_normal_regime_uses_cst_not_csosn_in_approval(): void
    {
        $policy=new NFCeProductionProfilePolicy(['BA:65:3:5102:20:01:01']);
        self::assertTrue($policy->approved('3', [
            'cfop_outbound_internal'=>'5102','icms_cst'=>'20','pis_cst'=>'01','cofins_cst'=>'01',
        ]));
        self::assertFalse($policy->approved('3', [
            'cfop_outbound_internal'=>'5102','icms_cst'=>'00','pis_cst'=>'01','cofins_cst'=>'01',
        ]));
    }
}
