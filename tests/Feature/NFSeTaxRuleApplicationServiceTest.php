<?php

namespace Tests\Feature;

use App\Models\CompanySetting;
use App\Models\FiscalDocumentJob;
use App\Models\FiscalTaxGroup;
use App\Services\Fiscal\NFSeTaxRuleApplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class NFSeTaxRuleApplicationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function job(FiscalTaxGroup $group): FiscalDocumentJob
    {
        CompanySetting::current()->update([
            'crt'=>'1',
            'city_ibge_code'=>'2926806',
            'state'=>'BA',
        ]);
        return FiscalDocumentJob::query()->create([
            'document_type'=>'nfse',
            'status'=>'prepared',
            'environment'=>'homologation',
            'source_snapshot'=>[
                'items'=>[[
                    'item_type'=>'service',
                    'service_id'=>1,
                    'fiscal_tax_group_id'=>$group->id,
                    'national_tax_code'=>'999999',
                    'service_list_item'=>'99.99',
                    'tax_defaults'=>[],
                ]],
            ],
        ]);
    }

    public function test_service_rule_applies_official_national_code_to_snapshot(): void
    {
        $group=FiscalTaxGroup::query()->where('preset_key','service_gym')->firstOrFail();
        $job=$this->job($group);
        app(NFSeTaxRuleApplicationService::class)->apply($job->id);
        $item=$job->fresh()->source_snapshot['items'][0];
        self::assertSame('060401',$item['national_tax_code']);
        self::assertSame('06.04',$item['service_list_item']);
        self::assertSame('1',$item['tax_defaults']['iss_exigibility']);
        self::assertSame($group->id,$item['tax_defaults']['fiscal_group_id']);
    }

    public function test_iss_rate_for_other_city_is_rejected(): void
    {
        $group=FiscalTaxGroup::query()->where('preset_key','service_accounting')->firstOrFail();
        $group->update(['tax_config'=>array_replace($group->tax_config, [
            'iss_rate'=>'3.00',
            'iss_city_ibge'=>'3550308',
            'iss_legal_reference'=>'Lei municipal específica',
        ])]);
        $job=$this->job($group);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('outro município');
        app(NFSeTaxRuleApplicationService::class)->apply($job->id);
    }

    public function test_missing_service_group_never_creates_a_guessed_classification(): void
    {
        $group=FiscalTaxGroup::query()->where('preset_key','service_accounting')->firstOrFail();
        $job=$this->job($group);
        $snapshot=$job->source_snapshot;
        $snapshot['items'][0]['fiscal_tax_group_id']=null;
        $job->update(['source_snapshot'=>$snapshot]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('selecione a tributação');
        app(NFSeTaxRuleApplicationService::class)->apply($job->id);
    }
}
