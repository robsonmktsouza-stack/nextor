<?php

namespace Tests\Unit;

use App\Support\ValidationFieldNames;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValidationFieldNamesTest extends TestCase
{
    #[DataProvider('operationalFields')]
    public function test_common_fields_keep_clear_labels_without_storage_paths(string $field, string $expected): void
    {
        $label=ValidationFieldNames::for($field);
        self::assertSame($expected,$label);
        self::assertStringNotContainsString('_',$label);
        self::assertDoesNotMatchRegularExpression('/\.\d+\./',$label);
    }

    public static function operationalFields(): array
    {
        return [
            'login'=>['password','senha'],
            'password confirmation'=>['password_confirmation','confirmação de senha'],
            'current password'=>['current_password','senha atual'],
            'purchase item'=>['items.2.unit_cost','custo unitário do item'],
            'payment alias'=>['payments.7.amount','valor do pagamento'],
            'delivery address'=>['delivery_addresses.3.zip_code','CEP do endereço de entrega'],
            'finance account'=>['financial_account_id','conta financeira'],
            'product stock'=>['stock_quantity','quantidade em estoque'],
            'nfe items'=>['items_json','itens da nota'],
            'nfe references'=>['references_json','documentos referenciados'],
            'nfe tax parameters'=>['tax_parameters_json','parâmetros tributários'],
            'roles'=>['permissions.2','permissão de acesso'],
            'catalog import'=>['sha256','Código de verificação do arquivo'],
            'api query'=>['updated_after','data da última atualização'],
        ];
    }

    public function test_nested_fiscal_variations_keep_the_field_and_scope_readable(): void
    {
        $state=ValidationFieldNames::for('tax_config.state_variations.2.ibs_uf_rate');
        $municipal=ValidationFieldNames::for('tax_config.municipal_variations.0.city_ibge');
        self::assertStringContainsString('IBS estadual',$state);
        self::assertStringContainsString('variação por UF',$state);
        self::assertStringContainsString('IBGE',$municipal);
        self::assertStringContainsString('variação por município',$municipal);
        self::assertStringNotContainsString('tax_config',$state);
        self::assertStringNotContainsString('municipal_variations',$municipal);
    }

    public function test_unknown_and_unsafe_fields_do_not_echo_internal_identifiers(): void
    {
        foreach(['private_unrecognized_key','items.3.private_unrecognized_key','<script>alert(1)</script>',str_repeat('x',501)] as $field){
            self::assertSame('campo informado',ValidationFieldNames::for($field));
        }
    }

    public function test_conditional_values_and_identifiers_do_not_require_technical_language(): void
    {
        $messages=require dirname(__DIR__,2).'/lang/pt_BR/validation.php';
        self::assertSame('conta a pagar',$messages['values']['type']['payable']);
        self::assertSame('homologação',$messages['values']['environment']['homologation']);
        self::assertStringNotContainsString('JSON',$messages['json']);
        self::assertStringNotContainsString('UUID',$messages['uuid']);
        self::assertSame('itens',$messages['attributes']['items']);
    }
}
