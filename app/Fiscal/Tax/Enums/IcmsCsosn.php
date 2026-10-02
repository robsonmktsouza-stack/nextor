<?php

namespace App\Fiscal\Tax\Enums;

enum IcmsCsosn: string
{
    case CSOSN_101 = '101';
    case CSOSN_102 = '102';
    case CSOSN_103 = '103';
    case CSOSN_201 = '201';
    case CSOSN_202 = '202';
    case CSOSN_203 = '203';
    case CSOSN_300 = '300';
    case CSOSN_400 = '400';
    case CSOSN_500 = '500';
    case CSOSN_900 = '900';

    public function xmlGroup(): string
    {
        return match ($this) {
            self::CSOSN_101 => 'ICMSSN101',
            self::CSOSN_102,
            self::CSOSN_103,
            self::CSOSN_300,
            self::CSOSN_400 => 'ICMSSN102',
            self::CSOSN_201 => 'ICMSSN201',
            self::CSOSN_202,
            self::CSOSN_203 => 'ICMSSN202',
            self::CSOSN_500 => 'ICMSSN500',
            self::CSOSN_900 => 'ICMSSN900',
        };
    }
}
