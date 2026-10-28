<?php

namespace Tests\Unit;

use App\Services\Fiscal\NFCeRtcTaxService;
use App\Services\Fiscal\Nfe55RtcTaxAdapter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class Nfe55RtcTaxAdapterTest extends TestCase
{
    private function parameters(): array
    {
        return ['ibs_cbs_cst'=>'000','ibs_cbs_class'=>'000001',
            'ibs_uf_rate'=>'0.1','ibs_municipal_rate'=>'0','cbs_rate'=>'0.9'];
    }

    public function test_rtc_uses_the_serialized_legacy_taxes_and_cent_exact_rounding(): void
    {
        $adapter=new Nfe55RtcTaxAdapter(new NFCeRtcTaxService());
        $result=$adapter->calculate($this->parameters(),11000,'3','2026-10-25',[
            'icms'=>1980,'pis'=>182,'cofins'=>836,
        ]);
        self::assertSame('80.02',$result['gibscbs']['vBC']);
        self::assertSame('0.08',$result['gibsuf']['vIBSUF']);
        self::assertSame('0.72',$result['gcbs']['vCBS']);
    }

    public function test_explicit_final_base_is_not_reduced_by_legacy_taxes_twice(): void
    {
        $result=(new Nfe55RtcTaxAdapter(new NFCeRtcTaxService()))->calculate(
            $this->parameters()+['ibs_cbs_base'=>'123.45'],10000,'3','2026-10-25',[
                'icms'=>1800,'pis'=>165,'cofins'=>760,
            ],true);
        self::assertSame('123.45',$result['gibscbs']['vBC']);
        self::assertSame('1.11',$result['gcbs']['vCBS']);
    }

    public function test_import_does_not_reuse_domestic_base_composition_implicitly(): void
    {
        $this->expectException(RuntimeException::class);
        (new Nfe55RtcTaxAdapter(new NFCeRtcTaxService()))->calculate($this->parameters(),10000,'3','2026-10-25',[
            'icms'=>1800,'pis'=>165,'cofins'=>760,
        ],true);
    }

    public function test_simples_2026_without_rtc_does_not_create_zero_valued_ibs_groups(): void
    {
        self::assertNull((new Nfe55RtcTaxAdapter(new NFCeRtcTaxService()))->calculate([],10000,'1','2026-10-25',[
            'icms'=>0,'pis'=>0,'cofins'=>0,
        ]));
    }
}
