<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\FiscalDocumentJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AdministrationPresentationTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\WithConfiguredCompany;

    private function admin(): User
    {
        return User::query()->create([
            'name'=>'Responsável', 'email'=>'presentation-admin@example.com',
            'password'=>'SenhaSegura123', 'role'=>'admin', 'is_active'=>true,
        ]);
    }

    private function xpath(string $html): \DOMXPath
    {
        $dom=new \DOMDocument();
        $previous=libxml_use_internal_errors(true);
        try { $dom->loadHTML('<?xml encoding="UTF-8">'.$html,LIBXML_NONET); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        return new \DOMXPath($dom);
    }

    public function test_settings_translate_fiscal_statuses_and_do_not_print_unrecognized_internal_codes(): void
    {
        $this->actingAs($this->admin());
        foreach (['prepared','pending','authorized','rejected','unknown_internal_state'] as $index=>$status) {
            FiscalDocumentJob::query()->create([
                'document_type'=>'nfce', 'status'=>$status, 'environment'=>'homologation',
                'emission_mode'=>'normal', 'series'=>1, 'document_number'=>$index+1,
                'cancellation_status'=>$status==='rejected'?'uncertain':null,
            ]);
        }
        $response=$this->get(route('settings.index',['tab'=>'fiscal']))->assertOk();
        $xpath=$this->xpath($response->getContent());
        $labels=[];
        foreach ($xpath->query('//table/tbody/tr/td[7]/span') as $cell) $labels[]=trim($cell->textContent);
        self::assertEqualsCanonicalizing(['Preparada','Aguardando confirmação','Autorizada','Rejeitada','A verificar'],$labels);
        $response->assertSee('NFC-e')->assertSee('Conferência necessária')
            ->assertDontSee('unknown_internal_state')->assertDontSee('settings-section-guide',false);
        self::assertSame(5,FiscalDocumentJob::query()->count());
        self::assertSame('unknown_internal_state',FiscalDocumentJob::query()->latest('id')->first()->status);
    }

    public function test_integration_addresses_are_on_demand_and_replacing_a_key_has_an_explicit_consequence(): void
    {
        $this->actingAs($this->admin());
        AppSetting::put('integrations','api_token','existing-private-test-key',true);
        $response=$this->get(route('settings.index',['tab'=>'integrations']))->assertOk();
        $xpath=$this->xpath($response->getContent());
        self::assertSame(3,$xpath->query('//details[summary[contains(.,"Endereços para integração")]]//code')->length);
        self::assertSame(0,$xpath->query('//details[@open][summary[contains(.,"Endereços para integração")]]')->length);
        $response->assertDontSee('existing-private-test-key')
            ->assertSee('As integrações que usam a chave atual precisarão ser atualizadas.');
    }

    public function test_explicit_key_generation_remains_available_and_the_plain_key_is_shown_once(): void
    {
        $this->actingAs($this->admin());
        $this->post(route('settings.api-token.regenerate'))
            ->assertRedirect(route('settings.index',['tab'=>'integrations']))
            ->assertSessionHas('success','Chave de acesso gerada.')
            ->assertSessionHas('api_token_plain');
        $key=session('api_token_plain');
        self::assertSame(64,strlen($key));
        self::assertSame($key,AppSetting::value('integrations','api_token'));
        $this->get(route('settings.index',['tab'=>'integrations']))->assertOk()->assertSee($key);
        $this->get(route('settings.index',['tab'=>'integrations']))->assertOk()->assertDontSee($key);
    }

    public function test_an_invalid_certificate_has_an_actionable_message_without_crypto_implementation_names(): void
    {
        Storage::fake('local');
        $this->actingAs($this->admin());
        $this->post(route('settings.certificate.upload'),[
            'certificate'=>UploadedFile::fake()->create('certificado.pfx',1),
            'certificate_password'=>'senhaDeTeste',
        ])->assertSessionHasErrors([
            'certificate'=>'Certificado inválido ou senha incorreta. Confira o arquivo .pfx/.p12 e a senha.',
        ]);
        self::assertSame([],Storage::disk('local')->allFiles());
    }

    public function test_login_loads_the_shared_message_filter_before_the_login_handler(): void
    {
        $html=$this->get(route('login'))->assertOk()->getContent();
        self::assertLessThan(strpos($html,'js/login.js'),strpos($html,'js/user-messages.js'));
    }
}
