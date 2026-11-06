<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class Nfe55AccountantSettingsTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\WithConfiguredCompany;

    private function admin(): User
    {
        return User::query()->create([
            'name'=>'Administrador', 'email'=>'accountant-settings@example.com',
            'password'=>'senhaSegura123', 'role'=>'admin', 'is_active'=>true,
        ]);
    }

    public function test_existing_accounting_registration_saves_masked_cpf_and_cnpj_as_digits(): void
    {
        $this->actingAs($this->admin());
        foreach (['529.982.247-25'=>'52998224725','11.222.333/0001-81'=>'11222333000181'] as $masked=>$digits) {
            $this->post(route('settings.group.update','accounting'),[
                'office_name'=>'Escritório Teste', 'accountant_name'=>'Contador Teste',
                'accountant_document'=>$masked, 'crc'=>'BA-123456',
                'email'=>'contador@example.com', 'accountant_xml_authorized'=>'1',
            ])->assertSessionHasNoErrors()->assertRedirect(route('settings.index',['tab'=>'accounting']));

            self::assertSame($digits,AppSetting::value('accounting','accountant_document'));
            self::assertSame('Contador Teste',AppSetting::value('accounting','accountant_name'));
            self::assertSame('Escritório Teste',AppSetting::value('accounting','office_name'));
            self::assertSame('BA-123456',AppSetting::value('accounting','crc'));
            self::assertSame('contador@example.com',AppSetting::value('accounting','email'));
            self::assertTrue(AppSetting::value('accounting','accountant_xml_authorized'));
            self::assertFalse(AppSetting::value('accounting','nfe_ba_sefaz_xml_authorized'));
        }
    }

    public function test_invalid_documents_are_rejected_without_changing_registration(): void
    {
        AppSetting::put('accounting','accountant_name','Contador anterior');
        $this->actingAs($this->admin());
        foreach (['52998224726','11222333000182','00000000000','11111111111111','ABC52998224725','123'] as $document) {
            $this->post(route('settings.group.update','accounting'),[
                'accountant_name'=>'Cadastro inválido', 'accountant_document'=>$document,
            ])->assertSessionHasErrors('accountant_document');
            self::assertSame('Contador anterior',AppSetting::value('accounting','accountant_name'));
        }
    }

    public function test_name_and_email_only_do_not_grant_xml_access_or_assign_a_document(): void
    {
        $this->actingAs($this->admin())->post(route('settings.group.update','accounting'),[
            'accountant_name'=>'Contador parcial', 'email'=>'contador@example.com',
        ])->assertSessionHasNoErrors();

        self::assertSame('Contador parcial',AppSetting::value('accounting','accountant_name'));
        self::assertNull(AppSetting::value('accounting','accountant_document'));
        self::assertFalse(AppSetting::value('accounting','accountant_xml_authorized'));
        self::assertFalse(AppSetting::value('accounting','nfe_ba_sefaz_xml_authorized'));
    }

    public function test_accountant_authorization_requires_a_document_even_when_bahia_fallback_is_selected(): void
    {
        $this->actingAs($this->admin())->post(route('settings.group.update','accounting'),[
            'accountant_name'=>'Contador parcial', 'accountant_xml_authorized'=>'1',
            'nfe_ba_sefaz_xml_authorized'=>'1',
        ])->assertSessionHasErrors('accountant_document');

        self::assertNull(AppSetting::value('accounting','accountant_xml_authorized'));
    }

    public function test_bahia_fallback_is_explicit_and_both_authorizations_can_be_revoked(): void
    {
        $this->actingAs($this->admin())->post(route('settings.group.update','accounting'),[
            'nfe_ba_sefaz_xml_authorized'=>'1',
        ])->assertSessionHasNoErrors();
        self::assertTrue(AppSetting::value('accounting','nfe_ba_sefaz_xml_authorized'));
        self::assertNull(AppSetting::value('accounting','accountant_document'));

        $this->post(route('settings.group.update','accounting'),[
            'accountant_document'=>'52998224725', 'accountant_xml_authorized'=>'1',
            'nfe_ba_sefaz_xml_authorized'=>'1',
        ])->assertSessionHasNoErrors();
        self::assertTrue(AppSetting::value('accounting','accountant_xml_authorized'));
        $this->post(route('settings.group.update','accounting'),[
            'accountant_document'=>'52998224725',
        ])->assertSessionHasNoErrors();
        self::assertFalse(AppSetting::value('accounting','accountant_xml_authorized'));
        self::assertFalse(AppSetting::value('accounting','nfe_ba_sefaz_xml_authorized'));
        self::assertSame('52998224725',AppSetting::value('accounting','accountant_document'));
    }

    public function test_fiscal_settings_link_to_existing_accountant_registration_and_explain_full_xml_access(): void
    {
        $this->actingAs($this->admin());
        foreach (['fiscal','nfe'] as $tab) {
            $this->get(route('settings.index',['tab'=>$tab]))->assertOk()
                ->assertSee(route('settings.index',['tab'=>'accounting']),false)
                ->assertSee('Contador e autorização de acesso ao XML da NF-e');
        }
        $this->get(route('settings.index',['tab'=>'accounting']))->assertOk()
            ->assertSee('name="accountant_document"',false)
            ->assertSee('name="accountant_xml_authorized"',false)
            ->assertSee('name="nfe_ba_sefaz_xml_authorized"',false)
            ->assertSee('XML completo da NF-e')
            ->assertSee('13.937.073/0001-56');
    }

    public function test_accountant_settings_keep_settings_permission_required(): void
    {
        $this->post(route('settings.group.update','accounting'),[
            'nfe_ba_sefaz_xml_authorized'=>'1',
        ])->assertRedirect('/login');
        $user=User::query()->create([
            'name'=>'Fiscal', 'email'=>'fiscal-accountant@example.com',
            'password'=>'senhaSegura123', 'role'=>'sales', 'is_active'=>true,
            'permissions'=>['fiscal'],
        ]);
        $this->actingAs($user)->get(route('settings.index',['tab'=>'accounting']))->assertForbidden();
        $this->post(route('settings.group.update','accounting'),[
            'nfe_ba_sefaz_xml_authorized'=>'1',
        ])->assertForbidden();
        self::assertNull(AppSetting::value('accounting','nfe_ba_sefaz_xml_authorized'));
    }
}
