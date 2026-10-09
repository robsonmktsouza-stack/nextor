<?php

namespace Tests\Feature;

use App\Models\CompanySetting;
use App\Models\FiscalTaxGroup;
use App\Models\Service;
use App\Support\FiscalServicePresetCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FiscalServicePresetTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_populates_24_genuine_national_service_codes(): void
    {
        $presets=FiscalTaxGroup::query()->where('kind','services')
            ->where('preset_key','like','service_%')->get();
        self::assertCount(24, $presets);
        self::assertCount(24, FiscalServicePresetCatalog::all());
        self::assertTrue($presets->every(fn($group)=>$group->is_active
            && !$group->is_default
            && $group->iss_exigibility === '1'
            && preg_match('/^[0-9]{6}$/', $group->tax_config['national_tax_code']) === 1
            && preg_match('/^[0-9]{2}\.[0-9]{2}$/', $group->tax_config['service_list_item']) === 1
            && !array_key_exists('iss_rate', $group->tax_config)));
    }

    public function test_service_classification_sets_national_code_and_service_list_item_on_save(): void
    {
        $group=FiscalTaxGroup::query()->where('preset_key','service_accounting')->firstOrFail();
        $data=[
            'name'=>'Contabilidade mensal',
            'sale_price'=>'350.00',
            'fiscal_tax_group_id'=>$group->id,
            'national_tax_code'=>'999999',
            'service_list_item'=>'99.99',
        ];
        $controller=app(\App\Http\Controllers\ServiceController::class);
        $request=\Illuminate\Http\Request::create('/services','POST',$data);
        // O cadastro é gravado sem depender de campo fiscal digitado pelo operador.
        $method=new \ReflectionMethod($controller,'persist');
        $service=$method->invoke($controller,$request);
        self::assertInstanceOf(Service::class,$service);
        self::assertSame('171901',$service->national_tax_code);
        self::assertSame('17.19',$service->service_list_item);
        self::assertSame('1',$service->tax_defaults['iss_exigibility']);
        self::assertSame($group->id,$service->fiscal_tax_group_id);
    }

    public function test_service_form_receives_all_group_templates(): void
    {
        $company=CompanySetting::current();
        $company->update(['crt'=>'1']);
        $controller=app(\App\Http\Controllers\ServiceController::class);
        // O controlador pode listar grupos de serviço sem ratear ISS nem inferir impostos.
        $reflection=new \ReflectionMethod($controller,'serviceTaxGroups');
        $groups=$reflection->invoke($controller);
        self::assertCount(24,$groups->whereNotNull('preset_key'));
        self::assertNotNull($groups->firstWhere('preset_key','service_gym'));
    }
}
