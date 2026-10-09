<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\CompanySetting;
use App\Models\User;
use App\Services\Fiscal\FiscalDocumentSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FiscalDocumentSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::query()->create([
            'name'=>'Administrador',
            'email'=>'admin-fiscal-central@example.com',
            'password'=>'senhaSegura123',
            'role'=>'admin',
            'is_active'=>true,
        ]);
    }

    public function test_all_five_fiscal_documents_share_an_explicit_configuration_policy(): void
    {
        $configuration=app(FiscalDocumentSettings::class);
        self::assertSame(['nfe','nfce','nfse','cte','mdfe'],FiscalDocumentSettings::documentTypes());

        AppSetting::put('fiscal','enabled',true);
        foreach ([
            'nfe'=>['nfe','enabled','environment','production_enabled'],
            'nfce'=>['nfce','enabled','environment','production_enabled'],
            'nfse'=>['nfse','enabled','environment','production_enabled'],
            'cte'=>['cte','cte_enabled','cte_environment','cte_production_enabled'],
            'mdfe'=>['cte','mdfe_enabled','mdfe_environment','mdfe_production_enabled'],
        ] as $kind=>[$group,$enableKey,$environmentKey,$productionKey]) {
            AppSetting::put($group,$enableKey,true);
            AppSetting::put($group,$environmentKey,'production');

            self::assertTrue($configuration->enabled($kind));
            self::assertSame('production',$configuration->environment($kind));
            self::assertFalse($configuration->productionAuthorized($kind));
            self::assertNotNull($configuration->transmissionIssue($kind,'production'));

            AppSetting::put($group,$productionKey,true);
            self::assertTrue($configuration->productionAuthorized($kind));
            self::assertNull($configuration->transmissionIssue($kind,'production'));
        }
    }

    public function test_disabled_document_cannot_be_sent_even_if_production_is_authorized(): void
    {
        AppSetting::put('fiscal','enabled',true);
        AppSetting::put('nfce','production_enabled',true);
        $settings=app(FiscalDocumentSettings::class);
        self::assertNotNull($settings->transmissionIssue('nfce','production'));

        AppSetting::put('nfce','enabled',true);
        AppSetting::put('fiscal','enabled',false);
        self::assertNotNull($settings->transmissionIssue('nfce','production'));
    }

    public function test_admin_can_save_production_options_across_all_fiscal_tabs(): void
    {
        $this->actingAs($this->admin());

        foreach ([
            'nfe'=>['environment'=>'production','series'=>1,'next_number'=>1,'production_enabled'=>'1'],
            'nfce'=>['environment'=>'production','series'=>1,'next_number'=>1,'production_enabled'=>'1'],
            'nfse'=>['environment'=>'production','next_rps'=>1,'production_enabled'=>'1'],
            'cte'=>[
                'cte_environment'=>'production','cte_series'=>1,'cte_next_number'=>1,
                'mdfe_environment'=>'production','mdfe_series'=>1,'mdfe_next_number'=>1,
                'cte_production_enabled'=>'1','mdfe_production_enabled'=>'1',
            ],
        ] as $tab=>$data) {
            $this->post(route('settings.group.update',$tab),$data)
                ->assertSessionHasNoErrors();
        }

        foreach (['nfe','nfce','nfse'] as $tab) {
            self::assertTrue((bool)AppSetting::value($tab,'production_enabled',false));
            $this->get(route('settings.index',['tab'=>$tab]))
                ->assertOk()->assertSee('Permitir emissão em produção');
        }
        self::assertTrue((bool)AppSetting::value('cte','cte_production_enabled',false));
        self::assertTrue((bool)AppSetting::value('cte','mdfe_production_enabled',false));
    }

    public function test_crt_must_match_company_tax_regime(): void
    {
        $this->actingAs($this->admin())
            ->post(route('settings.company.update'),[
                'tax_regime'=>'mei',
                'crt'=>'3',
                'timezone'=>'America/Bahia',
            ])
            ->assertSessionHasErrors('crt');

        $this->post(route('settings.company.update'),[
            'tax_regime'=>'mei',
            'crt'=>'4',
            'timezone'=>'America/Bahia',
        ])->assertSessionHasNoErrors();

        self::assertSame('4',(string)CompanySetting::current()->fresh()->crt);
    }
}
