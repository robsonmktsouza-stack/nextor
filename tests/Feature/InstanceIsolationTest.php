<?php

namespace Tests\Feature;

use App\Models\CompanySetting;
use App\Models\FiscalDocumentJob;
use App\Models\User;
use App\Services\Fiscal\NFCePreflightService;
use App\Support\InstanceIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class InstanceIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT='61288313000181';
    private const FOREIGN='39323356000100';

    private function admin(): User
    {
        return User::query()->create([
            'name'=>'Administrador',
            'email'=>'instance-admin@example.com',
            'password'=>'senhaSegura123',
            'role'=>'admin',
            'is_active'=>true,
        ]);
    }

    public function test_company_settings_reject_another_cnpj_without_touching_the_registered_entity(): void
    {
        config()->set('instance.cnpj',self::TENANT);
        $company=CompanySetting::current();
        $company->update(['document'=>self::TENANT,'legal_name'=>'Empresa contratante']);

        $this->actingAs($this->admin())
            ->post(route('settings.company.update'),[
                'document'=>self::FOREIGN,
                'legal_name'=>'Empresa estranha',
                'tax_regime'=>'simples_nacional',
                'crt'=>'1',
                'timezone'=>'America/Sao_Paulo',
            ])
            ->assertSessionHasErrors('document');

        self::assertSame(self::TENANT,$company->fresh()->document);
        self::assertSame('Empresa contratante',$company->fresh()->legal_name);
    }

    public function test_matching_cnpj_can_be_configured_from_the_existing_ui(): void
    {
        config()->set('instance.cnpj',self::TENANT);

        $this->actingAs($this->admin())
            ->post(route('settings.company.update'),[
                'document'=>self::TENANT,
                'legal_name'=>'Empresa contratante',
                'tax_regime'=>'simples_nacional',
                'crt'=>'1',
                'timezone'=>'America/Sao_Paulo',
            ])
            ->assertSessionHasNoErrors();

        self::assertSame(self::TENANT,CompanySetting::current()->fresh()->document);
        self::assertTrue(InstanceIdentity::matches(self::TENANT));
        self::assertFalse(InstanceIdentity::matches(self::FOREIGN));
    }

    public function test_preflight_blocks_issuer_cnpj_different_from_managed_installation(): void
    {
        config()->set('instance.cnpj',self::TENANT);
        CompanySetting::current()->update(['document'=>self::FOREIGN]);
        $job=FiscalDocumentJob::query()->create([
            'document_type'=>'nfce',
            'status'=>'prepared',
            'environment'=>'homologation',
            'series'=>1,
            'document_number'=>1,
        ]);
        $errors=app(NFCePreflightService::class)->validate($job);
        self::assertContains('CNPJ do emitente não corresponde à instalação contratada.',$errors);
    }

    public function test_acbr_transmission_refuses_foreign_cnpj_before_loading_library(): void
    {
        config()->set('instance.cnpj',self::TENANT);
        $company=CompanySetting::current();
        $company->update(['document'=>self::FOREIGN]);
        $job=FiscalDocumentJob::query()->create([
            'document_type'=>'nfce','status'=>'prepared',
            'environment'=>'homologation',
            'series'=>1,'document_number'=>12,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CNPJ do emitente não corresponde');
        app(\App\Services\Fiscal\NFCeTransmissionService::class)
            ->configure(new \App\Services\Fiscal\ACBr\ACBrNFeService(),$company,$job,'','');
    }

    public function test_local_development_remains_compatible_without_tenant_binding(): void
    {
        config()->set('instance.cnpj','');
        self::assertTrue(InstanceIdentity::matches(self::FOREIGN));
    }
}
