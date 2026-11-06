<?php

namespace Tests\Unit;

use App\Models\CompanySetting;
use App\Models\NfeDraft;
use App\Models\NfeDraftItem;
use App\Models\OperationNature;
use App\Services\Fiscal\Nfe55OperationPolicy;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;
use RuntimeException;

final class Nfe55OperationPolicyTest extends TestCase
{
    private function draft(string $direction = 'outbound', string $destination = 'internal'): NfeDraft
    {
        $note = new NfeDraft([
            'operation_type'=>$direction, 'destination'=>$destination, 'purpose'=>'normal',
            'issue_date'=>'2026-10-25','issue_time'=>'12:00',
            'recipient_snapshot'=>[
                'document'=>'12345678000200','state'=>$destination === 'internal' ? 'BA' : 'SP',
                'city_ibge_code'=>$destination === 'internal' ? '2927408' : '3550308',
                'ie_indicator'=>'contributor','state_registration'=>'12345678',
            ],
            'payment_type'=>'01','payments'=>[['type'=>'01','amount'=>100]],
        ]);
        $note->setRelation('operationNature', new OperationNature([
            'name'=>'Operação parametrizada','operation_type'=>$direction,'is_active'=>true,
        ]));
        $note->setRelation('items', new Collection([new NfeDraftItem([
            'item_number'=>1, 'cfop'=>($direction === 'outbound'
                ? ($destination === 'internal' ? '5' : '6')
                : ($destination === 'internal' ? '1' : '2')).'102',
            'tax_data'=>[],
        ])]));
        return $note;
    }

    private function company(): CompanySetting
    {
        return new CompanySetting(['document'=>'12345678000100','state'=>'BA','city_ibge_code'=>'2927408']);
    }

    public function test_entry_exit_and_domestic_destination_map_to_official_identification_codes(): void
    {
        foreach ([['outbound','internal','1','1','5'],['outbound','interstate','1','2','6'],
            ['inbound','internal','0','1','1'],['inbound','interstate','0','2','2']] as [$type,$dest,$tpNF,$idDest,$prefix]) {
            $actual = (new Nfe55OperationPolicy())->validate($this->draft($type,$dest),$this->company());
            self::assertSame($tpNF,$actual['tpNF']);
            self::assertSame($idDest,$actual['idDest']);
            self::assertSame($prefix,$actual['cfop_prefix']);
        }
    }

    public function test_cfop_direction_is_not_inferred_or_rewritten(): void
    {
        $draft = $this->draft('inbound');
        $draft->items[0]->cfop = '5102';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CFOP incompatível');
        (new Nfe55OperationPolicy())->validate($draft,$this->company());
    }

    public function test_interstate_final_consumer_without_difal_is_blocked(): void
    {
        $draft = $this->draft('outbound','interstate');
        $company=$this->company();$company->crt='3';
        $draft->final_consumer = true;
        $draft->recipient_snapshot = array_replace($draft->recipient_snapshot,['ie_indicator'=>'non_contributor']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DIFAL');
        (new Nfe55OperationPolicy())->validate($draft,$company);
    }

    public function test_devolution_requires_references_and_generates_fin_nfe_four(): void
    {
        $draft = $this->draft();
        $draft->purpose = 'return';
        $draft->payment_type = '90';
        $draft->payments = [['type'=>'90','amount'=>0]];
        $draft->items[0]->cfop = '5202';
        try {
            (new Nfe55OperationPolicy())->validate($draft,$this->company());
            self::fail('A devolução não pode omitir o documento de origem.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('referenciado',$error->getMessage());
        }
        $draft->references = [['type'=>'nfe','key'=>str_repeat('1',44)]];
        self::assertSame('4',(new Nfe55OperationPolicy())->validate($draft,$this->company())['finNFe']);
    }

    public function test_return_cfop_cannot_be_transmitted_as_normal_sale(): void
    {
        $draft = $this->draft();
        $draft->items[0]->cfop = '5202';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CFOP de devolução');
        (new Nfe55OperationPolicy())->validate($draft,$this->company());
    }

    public function test_transfer_requires_distinct_establishments_of_the_same_holder(): void
    {
        $draft = $this->draft();
        $draft->custom_fields = ['fiscal_operation'=>'transfer'];
        $draft->payment_type = '90';
        $draft->payments = [['type'=>'90','amount'=>0]];
        $draft->items[0]->cfop = '5152';
        $draft->items[0]->tax_data = ['fiscal_rule_trace'=>[
            'legal_source'=>'Regra aprovada pelo responsável fiscal', 'snapshot'=>['fiscal_operation'=>'transfer'],
        ]];
        (new Nfe55OperationPolicy())->validate($draft,$this->company());
        $draft->recipient_snapshot = array_replace($draft->recipient_snapshot,['document'=>'98765432000100']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('mesmo titular');
        (new Nfe55OperationPolicy())->validate($draft,$this->company());
    }

    public function test_sample_requires_configured_legal_treatment_and_correct_cfop(): void
    {
        $draft = $this->draft();
        $draft->custom_fields = ['fiscal_operation'=>'sample'];
        $draft->payment_type = '90';
        $draft->payments = [['type'=>'90','amount'=>0]];
        $draft->items[0]->cfop = '5911';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fundamento legal');
        (new Nfe55OperationPolicy())->validate($draft,$this->company());
    }

    public function test_ibge_municipality_must_belong_to_the_declared_state(): void
    {
        $draft = $this->draft();
        $draft->recipient_snapshot = array_replace($draft->recipient_snapshot,['city_ibge_code'=>'3550308']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('código IBGE');
        (new Nfe55OperationPolicy())->validate($draft,$this->company());
    }

    public function test_foreign_operation_cannot_reuse_an_ordinary_domestic_recipient(): void
    {
        $draft = $this->draft();
        $draft->destination = 'foreign';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('operação fiscal específica');
        (new Nfe55OperationPolicy())->validate($draft,$this->company());
    }

    public function test_residual_codes_require_a_reviewed_rule_and_only_declared_credit_requires_contributor(): void
    {
        $draft=$this->draft();
        $company=$this->company();$company->crt='1';
        $draft->recipient_snapshot=array_replace($draft->recipient_snapshot,['ie_indicator'=>'non_contributor']);
        $draft->items[0]->tax_data=['icms_csosn'=>'900','icms_base'=>'100','icms_rate'=>'0','icms_base_mode'=>'3'];
        try {
            (new Nfe55OperationPolicy())->validate($draft,$company);
            self::fail('Código residual sem fundamento legal.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('fundamento legal',$error->getMessage());
        }
        $tax=$draft->items[0]->tax_data;
        $tax['fiscal_rule_trace']=['legal_source'=>'https://www.confaz.fazenda.gov.br/legislacao'];
        $draft->items[0]->tax_data=$tax;
        self::assertSame('1',(new Nfe55OperationPolicy())->validate($draft,$company)['crt']);
        $draft->items[0]->tax_data=$tax+['icms_credit_rate'=>'3'];
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Crédito do Simples');
        (new Nfe55OperationPolicy())->validate($draft,$company);
    }
}
