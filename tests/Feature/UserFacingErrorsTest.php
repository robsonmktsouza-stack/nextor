<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Sale;
use App\Support\UserFacingMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class UserFacingErrorsTest extends TestCase
{
    use RefreshDatabase;

    public function test_unexpected_json_and_html_errors_never_expose_debug_data_even_in_debug_mode(): void
    {
        config(['app.debug'=>true]);
        Route::get('/presentation-error-fixture', fn()=>throw new \RuntimeException('SQLSTATE fixture-private /workspace/private/database.php'));
        $this->getJson('/presentation-error-fixture')->assertStatus(500)
            ->assertJsonStructure(['message'])->assertJsonMissingPath('exception')
            ->assertJsonMissingPath('trace')->assertDontSee('fixture-private')->assertDontSee('SQLSTATE');
        $this->get('/presentation-error-fixture')->assertStatus(500)
            ->assertSee('Não foi possível concluir')->assertSee('Voltar ao sistema')
            ->assertDontSee('fixture-private')->assertDontSee('SQLSTATE');
    }

    public function test_business_http_error_and_retry_header_remain_actionable(): void
    {
        Route::get('/presentation-business-fixture', fn()=>abort(409,'Estoque insuficiente para o produto selecionado.'));
        $this->getJson('/presentation-business-fixture')->assertStatus(409)
            ->assertJsonPath('message','Estoque insuficiente para o produto selecionado.');
        Route::get('/presentation-throttle-fixture', fn()=>abort(429,'Too Many Requests',['Retry-After'=>'60']));
        $this->getJson('/presentation-throttle-fixture')->assertStatus(429)->assertHeader('Retry-After','60')
            ->assertSee('Aguarde antes de tentar novamente')->assertDontSee('Too Many Requests');
    }

    public function test_validation_keeps_machine_keys_and_shows_the_business_field_name(): void
    {
        Route::post('/presentation-validation-fixture', function (\Illuminate\Http\Request $request) {
            return $request->validate(['items.*.unit_price'=>['required','numeric','min:0'],
                'financial_account_id'=>['required','integer']]);
        });
        $response=$this->postJson('/presentation-validation-fixture',['items'=>[['unit_price'=>'inválido']]])
            ->assertUnprocessable()->assertJsonValidationErrors(['items.0.unit_price','financial_account_id'])
            ->assertDontSee('The given data was invalid');
        self::assertStringNotContainsString('unit_price',implode(' ',collect($response->json('errors'))->flatten()->all()));
    }

    public function test_helper_preserves_domain_errors_and_sefaz_but_filters_library_diagnostics(): void
    {
        self::assertSame('Quantidade insuficiente.',UserFacingMessage::fromThrowable(new \RuntimeException('Quantidade insuficiente.'),'Operação indisponível.'));
        self::assertSame('Este XML não pertence ao CNPJ desta empresa.',UserFacingMessage::fromThrowable(new \InvalidArgumentException('Este XML não pertence ao CNPJ desta empresa.'),'Confira o arquivo.'));
        self::assertSame('Tente novamente.',UserFacingMessage::fromThrowable(new \RuntimeException('proc_open: /app/private executable failed'),'Tente novamente.'));
        $rejection='Rejeição: Informado NCM inexistente [nItem:1]';
        self::assertSame($rejection,UserFacingMessage::fromText($rejection));
    }

    public function test_api_date_filter_is_validated_without_query_diagnostics(): void
    {
        AppSetting::put('integrations','api_enabled',true);
        AppSetting::put('integrations','api_token','fixture-audit-token');
        foreach (['products','customers','sales'] as $resource) {
            $this->withToken('fixture-audit-token')->getJson('/api/lumeron/'.$resource.'?updated_after=invalid-date')
                ->assertUnprocessable()->assertJsonValidationErrors('updated_after')->assertDontSee('SQLSTATE');
        }
    }

    public function test_sales_api_preserves_ids_and_checkout_evidence_without_exposing_private_keys(): void
    {
        AppSetting::put('integrations','api_enabled',true);
        AppSetting::put('integrations','api_token','fixture-audit-token');
        $sale=Sale::query()->create(['operation_type'=>'sale','source'=>'pdv','status'=>'draft',
            'operation_date'=>'2026-10-26','subtotal'=>100,'discount_total'=>0,'total'=>100,
            'pdv_checkout_key'=>'fixture-private-key','pdv_checkout_hash'=>str_repeat('a',64)]);
        $this->withToken('fixture-audit-token')->getJson('/api/lumeron/sales')->assertOk()
            ->assertJsonPath('data.0.id',$sale->id)->assertJsonMissingPath('data.0.pdv_checkout_key')
            ->assertJsonMissingPath('data.0.pdv_checkout_hash');
        self::assertSame('fixture-private-key',$sale->fresh()->pdv_checkout_key);
        self::assertSame(str_repeat('a',64),$sale->fresh()->pdv_checkout_hash);
    }

    public function test_validation_does_not_flash_integration_secrets_back_into_forms(): void
    {
        Route::middleware('web')->post('/presentation-secret-fixture',function (\Illuminate\Http\Request $request) {
            return $request->validate(['name'=>['required']]);
        });
        $response=$this->from('/dashboard')->post('/presentation-secret-fixture',[
            'certificate_password'=>'fixture-a1-secret','csc_token'=>'fixture-csc-secret',
            'smtp_password'=>'fixture-mail-secret','client_secret'=>'fixture-client-secret',
        ])->assertRedirect('/dashboard')->assertSessionHasErrors('name');
        foreach (['certificate_password','csc_token','smtp_password','client_secret'] as $field) {
            $response->assertSessionMissing('_old_input.'.$field);
        }
    }
}
