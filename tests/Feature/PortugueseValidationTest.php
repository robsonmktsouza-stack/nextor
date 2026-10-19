<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\Concerns\WithConfiguredCompany;
use Tests\TestCase;

final class PortugueseValidationTest extends TestCase
{
    use RefreshDatabase;
    use WithConfiguredCompany { setUp as private setUpConfiguredCompany; }

    protected function setUp(): void
    {
        // Preservar o fixture de empresa que a configuração do próprio
        // teste sobrescrevia: sem emitente, o middleware redireciona para
        // Configurações antes de o PDV validar os campos.
        $this->setUpConfiguredCompany();
        $this->app->setLocale('pt_BR');
    }

    public function test_validation_rules_are_translated_into_portuguese(): void
    {
        self::assertSame('pt_BR', app()->getLocale());
        self::assertSame(
            'O campo quantidade deve ser maior que 0.',
            __('validation.gt.numeric', ['attribute' => 'quantidade', 'value' => 0])
        );

        foreach (['required', 'numeric', 'gt.numeric', 'gte.numeric', 'decimal', 'exists'] as $rule) {
            self::assertStringNotContainsString('validation.', __('validation.'.$rule));
        }
    }

    public function test_authentication_and_pagination_messages_are_also_translated(): void
    {
        self::assertSame('Os dados de acesso informados não conferem.', __('auth.failed'));
        self::assertSame('Não encontramos uma conta com esse e-mail.', __('passwords.user'));
        self::assertSame('Próximo', __('pagination.next'));
    }

    public function test_decimal_errors_do_not_expose_translation_placeholders(): void
    {
        $validator = Validator::make(
            ['amount' => '12.345'],
            ['amount' => ['numeric', 'decimal:0,2']]
        );

        self::assertTrue($validator->fails());
        $message = $validator->errors()->first('amount');
        self::assertStringContainsString('casas decimais', $message);
        self::assertStringNotContainsString(':decimal', $message);
        self::assertStringNotContainsString('validation.', $message);
    }

    public function test_zero_quantity_and_zero_payment_have_human_readable_messages(): void
    {
        $validator = Validator::make(
            [
                'items' => [['quantity' => 0]],
                'payments' => [['amount' => 0]],
            ],
            [
                'items.*.quantity' => ['required', 'numeric', 'gt:0'],
                'payments.*.amount' => ['required', 'numeric', 'gt:0'],
            ]
        );

        self::assertTrue($validator->fails());
        self::assertSame(
            'A quantidade do produto deve ser maior que zero.',
            $validator->errors()->first('items.0.quantity')
        );
        self::assertSame(
            'O valor do pagamento deve ser maior que zero.',
            $validator->errors()->first('payments.0.amount')
        );
    }

    public function test_pdv_post_redirects_back_with_portuguese_validation_errors(): void
    {
        $user = User::query()->create([
            'name' => 'Operador PT-BR',
            'email' => 'operador-ptbr@example.com',
            'password' => 'senhaSegura123',
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->from(route('pdv.index'))
            ->post(route('pdv.store'), [
                'items' => [[
                    'item_type' => 'product',
                    'product_id' => 999999,
                    'quantity' => 0,
                ]],
                'payments' => [[
                    'payment_method' => 'cash',
                    'amount' => 0,
                ]],
            ])
            ->assertRedirect(route('pdv.index'))
            ->assertSessionHasErrors([
                'items.0.quantity',
                'payments.0.amount',
            ]);

        $errors = session('errors');
        self::assertNotNull($errors);
        self::assertSame(
            'A quantidade do produto deve ser maior que zero.',
            $errors->first('items.0.quantity')
        );
        self::assertSame(
            'O valor do pagamento deve ser maior que zero.',
            $errors->first('payments.0.amount')
        );
        foreach ($errors->all() as $message) {
            self::assertStringNotContainsString('validation.', $message);
        }
    }

    public function test_important_error_attribute_names_are_portuguese(): void
    {
        $validator = Validator::make(
            ['consumer_document' => str_repeat('1', 30)],
            ['consumer_document' => ['max:20']]
        );

        self::assertSame(
            'O campo CPF/CNPJ do consumidor deve ter no máximo 20 caracteres.',
            $validator->errors()->first('consumer_document')
        );
    }
}
