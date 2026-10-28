<?php

namespace Tests\Unit;

use App\Services\Fiscal\Nfe55DraftTotalsService;
use Tests\TestCase;

final class Nfe55DraftTotalsServiceTest extends TestCase
{
    private function item(array $tax=[],array $parts=[]): array
    {
        return array_replace(['quantity'=>'1','dimension_quantity'=>'1','unit_price'=>'100',
            'tax_data'=>array_replace(['icms_cst'=>'00','icms_rate'=>'18',
                'pis_cst'=>'01','pis_rate'=>'1.65','cofins_cst'=>'01','cofins_rate'=>'7.6',
                'ipi_cst'=>'50','ipi_enq'=>'999','ipi_rate'=>'10'],$tax)],$parts);
    }

    public function test_rates_use_the_net_allocated_base_and_invoice_only_adds_ipi_and_st(): void
    {
        $totals=app(Nfe55DraftTotalsService::class)->calculate([
            $this->item([],['freight'=>'10','insurance'=>'3','other_expenses'=>'2','discount'=>'5']),
        ],['issue_date'=>'2025-12-31','crt'=>'3','freight'=>'10','surcharge'=>'5','discount'=>'5']);
        self::assertSame(100.0,$totals['products']);
        self::assertSame(12.0,$totals['ipi']);
        self::assertSame(21.6,$totals['icms']);
        self::assertSame(1.98,$totals['pis']);
        self::assertSame(9.12,$totals['cofins']);
        self::assertSame(132.0,$totals['total']);
        self::assertTrue($totals['tax_calculation_complete']);
    }

    public function test_distribution_preserves_every_cent_and_non_taxed_ipi_has_no_estimated_amount(): void
    {
        $tax=['icms_csosn'=>'102','icms_rate'=>'','pis_cst'=>'06','pis_rate'=>'',
            'cofins_cst'=>'06','cofins_rate'=>'','ipi_cst'=>'53','ipi_rate'=>''];
        $totals=app(Nfe55DraftTotalsService::class)->calculate([
            $this->item($tax,['unit_price'=>'0.01']),$this->item($tax,['unit_price'=>'0.02']),
        ],['issue_date'=>'2025-12-31','crt'=>'1','freight'=>'0.01','discount'=>'0.01']);
        self::assertSame(0.03,$totals['total']);
        self::assertSame(0.0,$totals['ipi']);
        self::assertEquals(0.03,array_sum(array_column($totals['item_taxes'],'base')));
    }

    public function test_unknown_treatment_is_exposed_as_pending_instead_of_a_zero_tax(): void
    {
        $totals=app(Nfe55DraftTotalsService::class)->calculate([
            $this->item(['icms_cst'=>'61','pis_cst'=>'98']),
        ],['issue_date'=>'2025-12-31','crt'=>'3']);
        self::assertFalse($totals['tax_calculation_complete']);
        self::assertNull($totals['icms']);
        self::assertNull($totals['pis']);
        self::assertNotEmpty($totals['tax_errors']);
    }

    private function noFederalTax(): array
    {
        return ['pis_cst'=>'06','pis_rate'=>'','cofins_cst'=>'06','cofins_rate'=>'',
            'ipi_cst'=>'53','ipi_enq'=>'999','ipi_rate'=>''];
    }

    public function test_only_explicit_desoneration_deduction_reduces_invoice_total(): void
    {
        foreach(['0'=>100.0,'1'=>82.0] as $deduct=>$expected){
            $totals=app(Nfe55DraftTotalsService::class)->calculate([$this->item(array_replace($this->noFederalTax(),[
                'icms_cst'=>'40','icms_rate'=>'','icms_desoneration_amount'=>'18',
                'icms_desoneration_reason'=>'9','icms_desoneration_deduct'=>(string)$deduct,
            ]))],['issue_date'=>'2025-12-31','crt'=>'3']);
            self::assertSame(18.0,$totals['icms_desonerated']);
            self::assertSame($expected,$totals['total']);
        }
    }

    public function test_historical_fcp_is_reported_by_quantity_and_is_not_added_to_invoice(): void
    {
        $totals=app(Nfe55DraftTotalsService::class)->calculate([$this->item(array_replace($this->noFederalTax(),[
            'icms_cst'=>'60','icms_rate'=>'','st_retained_amount_scope'=>'unit',
            'icms_st_retained_base'=>'80','st_retained_rate'=>'18','icms_substitutor_value'=>'9',
            'icms_st_retained_value'=>'5','fcp_st_retained_base'=>'80','fcp_st_retained_rate'=>'2',
            'fcp_st_retained_value'=>'1.6',
        ]),['quantity'=>'2'])],['issue_date'=>'2025-12-31','crt'=>'3']);
        self::assertSame(3.2,$totals['fcp_st_retained']);
        self::assertSame(200.0,$totals['total']);
        self::assertTrue($totals['tax_calculation_complete']);
    }

    public function test_difal_and_destination_fcp_are_totalled_without_adding_them_again_to_invoice(): void
    {
        $totals=app(Nfe55DraftTotalsService::class)->calculate([$this->item(array_replace($this->noFederalTax(),[
            'icms_rate'=>'12','difal_base'=>'100','difal_fcp_base'=>'100',
            'difal_internal_rate'=>'18','difal_interstate_rate'=>'12','difal_share_rate'=>'100','difal_fcp_rate'=>'2',
        ]))],['issue_date'=>'2025-12-31','crt'=>'3']);
        self::assertSame(6.0,$totals['difal_destination']);
        self::assertSame(2.0,$totals['fcp_destination']);
        self::assertSame(100.0,$totals['total']);
    }
}
