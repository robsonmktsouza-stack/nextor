<?php

namespace Tests\Unit;

use App\Services\Fiscal\Nfe55DifalCalculator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class Nfe55DifalCalculatorTest extends TestCase
{
    private function parameters(): array
    {
        return ['difal_base'=>'100.00','difal_fcp_base'=>'100.00','difal_internal_rate'=>'18',
            'difal_interstate_rate'=>'12','difal_share_rate'=>'100','difal_fcp_rate'=>'2'];
    }

    public function test_destination_and_fcp_are_computed_from_explicit_bases_and_sharing(): void
    {
        $result=(new Nfe55DifalCalculator())->calculate($this->parameters(),'2026-10-25');
        self::assertSame(600,$result['destination_amount']);
        self::assertSame(200,$result['fcp_destination_amount']);
        self::assertSame(0,$result['origin_amount']);
        self::assertSame('100.0000',$result['fields']['pICMSInterPart']);
        self::assertSame('6.00',$result['fields']['vICMSUFDest']);
    }

    public function test_partilha_from_a_historic_period_is_not_reused_for_current_emission(): void
    {
        $this->expectException(RuntimeException::class);
        (new Nfe55DifalCalculator())->calculate(array_replace($this->parameters(),['difal_share_rate'=>'80']),'2026-10-25');
    }

    public function test_partial_parameters_are_not_filled_with_defaults(): void
    {
        $this->expectException(RuntimeException::class);
        (new Nfe55DifalCalculator())->calculate(['difal_base'=>'100'],'2026-10-25');
    }

    public function test_quantity_or_percentage_rates_are_not_fabricated_for_an_empty_profile(): void
    {
        self::assertNull((new Nfe55DifalCalculator())->calculate([],'2026-10-25'));
    }

    public function test_difal_rounds_at_the_item_and_fcp_can_have_a_distinct_approved_base(): void
    {
        $result=(new Nfe55DifalCalculator())->calculate(array_replace($this->parameters(),[
            'difal_base'=>'123.45','difal_fcp_base'=>'100.00','difal_internal_rate'=>'20.5',
        ]),'2026-10-25');
        self::assertSame(1049,$result['destination_amount']);
        self::assertSame(200,$result['fcp_destination_amount']);
    }
}
