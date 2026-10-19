<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\FiscalDocumentJob;
use App\Models\User;
use App\Services\Fiscal\NFCeEnvironmentParameters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\WithConfiguredCompany;
use Tests\TestCase;

final class NFCeProductionParametersTest extends TestCase
{
    use RefreshDatabase;
    use WithConfiguredCompany;

    private function admin(): User
    {
        return User::query()->create([
            'name'=>'Administrador NFC-e',
            'email'=>'admin-nfce-parameters@example.com',
            'password'=>'senhaSegura123',
            'role'=>'admin',
            'is_active'=>true,
        ]);
    }

    private function input(array $extra=[]): array
    {
        return array_replace([
            'enabled'=>'1',
            'environment'=>'homologation',
            'series'=>'1',
            'next_number'=>'67',
            'csc_id'=>'1',
            'csc_token'=>'CSC-HOMOLOGACAO-TESTE',
            'production_series'=>'2',
            'production_next_number'=>'5',
            'production_csc_id'=>'3',
            'production_csc_token'=>'CSC-PRODUCAO-TESTE',
            'print_danfe'=>'1',
        ],$extra);
    }

    public function test_missing_production_parameters_block_production_activation(): void
    {
        $this->actingAs($this->admin())
            ->post(route('settings.group.update','nfce'),$this->input([
                'environment'=>'production',
                'production_enabled'=>'1',
                'production_series'=>null,
                'production_next_number'=>null,
                'production_csc_id'=>null,
                'production_csc_token'=>null,
            ]))
            ->assertSessionHasErrors([
                'production_series',
                'production_next_number',
                'production_csc_id',
                'production_csc_token',
            ]);
        self::assertFalse((bool)AppSetting::value('nfce','production_enabled',false));
    }

    public function test_production_csc_is_encrypted_and_homologation_values_remain_intact(): void
    {
        $this->actingAs($this->admin())
            ->post(route('settings.group.update','nfce'),$this->input([
                'environment'=>'production',
                'production_enabled'=>'1',
            ]))->assertSessionHasNoErrors();

        $parameters=app(NFCeEnvironmentParameters::class);
        self::assertSame(1,$parameters->series('homologation'));
        self::assertSame(67,$parameters->nextNumber('homologation'));
        self::assertSame('CSC-HOMOLOGACAO-TESTE',$parameters->cscToken('homologation'));
        self::assertSame(2,$parameters->series('production'));
        self::assertSame(5,$parameters->nextNumber('production'));
        self::assertSame('3',$parameters->cscId('production'));
        self::assertSame('CSC-PRODUCAO-TESTE',$parameters->cscToken('production'));
        self::assertSame([], $parameters->issues('production'));

        $row=DB::table('app_settings')->where('group','nfce')
            ->where('key','production_csc_token')->first();
        self::assertNotNull($row);
        self::assertTrue((bool)$row->is_secret);
        self::assertNotSame('CSC-PRODUCAO-TESTE',$row->value);
    }

    public function test_changing_environment_never_advances_homologation_invoice_sequence(): void
    {
        AppSetting::put('nfce','next_number',85);
        AppSetting::put('nfce','production_next_number',12);
        $p=app(NFCeEnvironmentParameters::class);
        $number=AppSetting::reserveInteger('nfce',$p->numberKey('production'),1);
        self::assertSame(12,$number);
        self::assertSame(85,$p->nextNumber('homologation'));
        self::assertSame(13,$p->nextNumber('production'));
    }

    public function test_used_production_invoice_number_cannot_be_reused(): void
    {
        FiscalDocumentJob::query()->create([
            'document_type'=>'nfce',
            'status'=>'prepared',
            'environment'=>'production',
            'series'=>2,
            'document_number'=>12,
        ]);

        $this->actingAs($this->admin())
            ->post(route('settings.group.update','nfce'),$this->input([
                'production_next_number'=>'12',
                'production_enabled'=>'1',
            ]))
            ->assertSessionHasErrors('production_next_number');
    }

    public function test_new_configuration_fields_appear_in_settings_without_revealing_csc(): void
    {
        AppSetting::put('nfce','production_csc_token','CSC-PRODUCAO-SEGREDO',true);
        $html=$this->actingAs($this->admin())
            ->get(route('settings.index',['tab'=>'nfce']))
            ->assertOk()
            ->assertSee('Parâmetros de produção')
            ->assertSee('Próximo número em produção')
            ->getContent();
        self::assertStringNotContainsString('CSC-PRODUCAO-SEGREDO',$html);
        self::assertStringContainsString('name="production_csc_token"',$html);
    }
}
