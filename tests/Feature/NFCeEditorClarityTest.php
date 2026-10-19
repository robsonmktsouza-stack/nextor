<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithConfiguredCompany;
use Tests\TestCase;

final class NFCeEditorClarityTest extends TestCase
{
    use RefreshDatabase;
    use WithConfiguredCompany;

    private function admin(): User
    {
        return User::query()->create([
            'name'=>'Editor Fiscal',
            'email'=>'editor-clarity@example.com',
            'password'=>'senhaSegura123',
            'role'=>'admin',
            'is_active'=>true,
        ]);
    }

    public function test_new_invoice_has_tabs_without_redundant_fiscal_readonly_fields_or_instructions(): void
    {
        $response=$this->actingAs($this->admin())
            ->get(route('fiscal.nfce.create'))
            ->assertOk()
            ->assertSee('Dados gerais')
            ->assertSee('Consumidor')
            ->assertSee('Produtos')
            ->assertSee('Pagamento')
            ->assertSee('Resumo');

        $html=$response->getContent();
        self::assertStringNotContainsString('Série, ambiente e modalidade são controlados pelas Configurações fiscais.', $html);
        self::assertStringNotContainsString('class="nfce-compliance-note"', $html);
        self::assertStringNotContainsString('<span>Modelo fiscal</span>', $html);
        self::assertStringNotContainsString('<span>Série configurada</span>', $html);
        self::assertStringNotContainsString('<span>Regime da empresa (CRT)</span>', $html);
        self::assertStringNotContainsString('nfe-section-title">Dados gerais', $html);
    }

    public function test_form_keeps_fiscal_errors_and_user_actions_available(): void
    {
        $html=$this->actingAs($this->admin())
            ->get(route('fiscal.nfce.create'))->assertOk()->getContent();
        self::assertStringContainsString('id="nfceFormError"', $html);
        self::assertStringContainsString('id="nfceTab-general"', $html);
        self::assertStringContainsString('id="nfceTab-summary"', $html);
        self::assertStringContainsString('id="nfceAddProduct"', $html);
        self::assertStringContainsString('id="nfceAddPayment"', $html);
        self::assertStringContainsString('Emitir NFC-e', $html);
    }
}
