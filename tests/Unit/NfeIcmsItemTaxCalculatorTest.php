<?php

namespace Tests\Unit;

use App\Services\Fiscal\NfeIcmsItemTaxCalculator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NfeIcmsItemTaxCalculatorTest extends TestCase
{
    public function test_normal_regime_uses_cst_and_explicit_rate_and_fcp(): void
    {
        $result=(new NfeIcmsItemTaxCalculator())->calculate([
            'icms_cst'=>'00','icms_rate'=>'18','fcp_rate'=>'2',
        ],10000,'3');
        self::assertSame(1800,$result['icms_amount']);
        self::assertSame(200,$result['fcp_amount']);
        self::assertSame('00',$result['fields']['CST']);
        self::assertArrayNotHasKey('CSOSN',$result['fields']);
    }

    public function test_reduction_and_deferment_are_distinct_calculations(): void
    {
        $calc=new NfeIcmsItemTaxCalculator();
        $reduced=$calc->calculate(['icms_cst'=>'20','icms_rate'=>'18','icms_base_reduction'=>'33.3333'],10000,'3');
        self::assertSame(6667,$reduced['base_cents']);
        self::assertSame(1200,$reduced['icms_amount']);
        $deferred=$calc->calculate(['icms_cst'=>'51','icms_rate'=>'18','icms_deferment_rate'=>'33.3333'],10000,'2');
        self::assertSame('18.00',$deferred['fields']['vICMSOp']);
        self::assertSame('6.00',$deferred['fields']['vICMSDif']);
        self::assertSame(1200,$deferred['icms_amount']);
    }

    public function test_simples_credit_is_computed_without_creating_an_icms_debit(): void
    {
        $result=(new NfeIcmsItemTaxCalculator())->calculate(['icms_csosn'=>'101','icms_credit_rate'=>'3.1234'],10000,'1');
        self::assertSame(312,$result['credit_amount']);
        self::assertSame(0,$result['icms_amount']);
        self::assertSame('3.12',$result['fields']['vCredICMSSN']);
    }

    public function test_st_mva_deduction_and_fcp_st_are_totalized_separately(): void
    {
        $result=(new NfeIcmsItemTaxCalculator())->calculate([
            'icms_cst'=>'10','icms_rate'=>'18','icms_st_base_mode'=>'4',
            'icms_st_base'=>'100.00','icms_st_mva'=>'40','icms_st_rate'=>'18',
            'icms_st_deduction'=>'18.00','fcp_st_rate'=>'2',
        ],10000,'3');
        self::assertSame(14000,$result['st_base_cents']);
        self::assertSame(720,$result['st_amount']);
        self::assertSame(280,$result['fcp_st_amount']);
        self::assertSame(1000,$result['total_addition']);
    }

    public function test_st_never_assumes_an_icms_deduction(): void
    {
        $this->expectException(RuntimeException::class);
        (new NfeIcmsItemTaxCalculator())->calculate([
            'icms_csosn'=>'202','icms_st_base_mode'=>'6','icms_st_base'=>'100','icms_st_rate'=>'18',
        ],10000,'1');
    }

    public function test_missing_rate_and_unsupported_retained_groups_are_blocked(): void
    {
        foreach ([['icms_cst'=>'00'],['icms_cst'=>'90']] as $tax) {
            try { (new NfeIcmsItemTaxCalculator())->calculate($tax,10000,'3'); self::fail('Incomplete tax accepted.'); }
            catch (RuntimeException) { self::assertTrue(true); }
        }
    }

    public function test_no_tax_codes_do_not_accept_a_nonzero_rate(): void
    {
        $this->expectException(RuntimeException::class);
        (new NfeIcmsItemTaxCalculator())->calculate(['icms_csosn'=>'102','icms_rate'=>'18'],10000,'1');
    }

    public function test_retained_st_is_preserved_from_documented_values_without_new_st_addition(): void
    {
        foreach ([['3','60'],['1','500']] as [$crt,$code]) {
            $result=(new NfeIcmsItemTaxCalculator())->calculate([
                $crt==='3'?'icms_cst':'icms_csosn'=>$code,
                'st_retained_amount_scope'=>'line','icms_st_retained_base'=>'140.00',
                'st_retained_rate'=>'18','icms_substitutor_value'=>'18.00','icms_st_retained_value'=>'7.20',
                'fcp_st_retained_base'=>'140.00','fcp_st_retained_rate'=>'2','fcp_st_retained_value'=>'2.80',
            ],10000,$crt);
            self::assertSame('140.00',$result['fields']['vBCSTRet']);
            self::assertSame('7.20',$result['fields']['vICMSSTRet']);
            self::assertSame(280,$result['fcp_st_retained_amount']);
            self::assertSame(0,$result['total_addition']);
        }
    }

    public function test_unit_retention_uses_the_actual_taxable_quantity_once(): void
    {
        $result=(new NfeIcmsItemTaxCalculator())->calculate([
            'icms_cst'=>'60','st_retained_amount_scope'=>'unit',
            'icms_st_retained_base'=>'10.0000','st_retained_rate'=>'18',
            'icms_substitutor_value'=>'1.0000','icms_st_retained_value'=>'0.8000',
        ],10000,'3','2.5000');
        self::assertSame('25.00',$result['fields']['vBCSTRet']);
        self::assertSame('2.50',$result['fields']['vICMSSubstituto']);
        self::assertSame('2.00',$result['fields']['vICMSSTRet']);
    }

    public function test_desoneration_deduction_is_explicit_and_distinct_from_reported_relief(): void
    {
        foreach (['0'=>0,'1'=>1800] as $deduct=>$expected) {
            $result=(new NfeIcmsItemTaxCalculator())->calculate([
                'icms_cst'=>'40','icms_desoneration_amount'=>'18.00',
                'icms_desoneration_reason'=>'9','icms_desoneration_deduct'=>(string)$deduct,
            ],10000,'3');
            self::assertSame(1800,$result['desonerated_amount']);
            self::assertSame($expected,$result['total_deduction']);
            self::assertSame((string)$deduct,$result['fields']['indDeduzDeson']);
        }
    }

    public function test_desoneration_does_not_infer_whether_it_reduces_the_invoice(): void
    {
        $this->expectException(RuntimeException::class);
        (new NfeIcmsItemTaxCalculator())->calculate([
            'icms_cst'=>'40','icms_desoneration_amount'=>'18','icms_desoneration_reason'=>'9',
        ],10000,'3');
    }
}
