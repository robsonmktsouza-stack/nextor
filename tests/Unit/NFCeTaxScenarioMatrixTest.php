<?php

namespace Tests\Unit;

use App\Services\Fiscal\NFCeTaxCalculationService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The engine never GUESSes tax status. These fixtures cover the known XML
 * output families. Tax classification for a real NCM/UF still belongs to
 * configured, dated tax rules and official validation in homologation.
 */
final class NFCeTaxScenarioMatrixTest extends TestCase
{
    private function item(array $tax): array
    {
        return [
            'origin'=>'0','quantity'=>'2.000','unit_price'=>'50.00','discount'=>'5.00',
            'tax_defaults'=>array_replace([
                'cfop_outbound_internal'=>'5102',
                'icms_csosn'=>'102',
                'pis_cst'=>'49','cofins_cst'=>'49',
            ],$tax),
        ];
    }

    public function test_supported_icms_families_are_deterministic(): void
    {
        $service=new NFCeTaxCalculationService();

        $simple=$service->calculate($this->item([]),'1');
        self::assertSame('102',$simple['icms']['CSOSN']);
        self::assertSame('49',$simple['pis']['CST']);
        self::assertSame('49',$simple['cofins']['CST']);

        $mei=$service->calculate($this->item([]),'4');
        self::assertSame('102',$mei['icms']['CSOSN']);

        $st=$service->calculate($this->item([
            'cfop_outbound_internal'=>'5405','icms_csosn'=>'500',
            'st_retained_amount_scope'=>'line',
            'icms_st_retained_base'=>'60.00',
            'icms_st_retained_value'=>'10.80',
        ]),'1');
        self::assertSame('60.00',$st['icms']['vBCSTRet']);
        self::assertSame('10.80',$st['icms']['vICMSSTRet']);

        $normal=$service->calculate($this->item([
            'icms_cst'=>'00','mod_bc'=>'3','icms_rate'=>'20',
        ]),'3');
        self::assertSame('00',$normal['icms']['CST']);
        self::assertSame('95.00',$normal['icms']['vBC']);
        self::assertSame('19.00',$normal['icms']['vICMS']);
    }

    public function test_unmapped_status_or_incomplete_st_values_fail_closed(): void
    {
        $service=new NFCeTaxCalculationService();
        foreach([
            $this->item(['icms_csosn'=>'900']),
            $this->item(['cfop_outbound_internal'=>'5405','icms_csosn'=>'500']),
            $this->item(['cfop_outbound_internal'=>'6102']),
        ] as $item){
            try{
                $service->calculate($item,'1');
                self::fail('A operação não mapeada deve ser bloqueada.');
            }catch(RuntimeException){
                self::assertTrue(true);
            }
        }
    }
}
