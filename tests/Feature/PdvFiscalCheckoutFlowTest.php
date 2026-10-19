<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regressões do fluxo de caixa: uma venda pertence ao PDV; emissão e
 * autorização fiscal seguem o ciclo independente da SEFAZ.
 */
final class PdvFiscalCheckoutFlowTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\WithConfiguredCompany;

    private function admin(): User
    {
        return User::query()->create([
            'name'=>'Operador do caixa',
            'email'=>'pdv-fiscal-checkout@example.com',
            'password'=>'senhaSegura123',
            'role'=>'admin',
            'is_active'=>true,
        ]);
    }

    public function test_cashier_remains_the_destination_after_receipt_printing(): void
    {
        $receipt = file_get_contents(resource_path('views/pdv/receipt.blade.php'));
        self::assertNotFalse($receipt);
        self::assertStringContainsString("\$receiptAfterPrintUrl = route('pdv.index');", $receipt);
        self::assertStringContainsString('window.location.assign(afterPrintUrl)', $receipt);

        $calculation = substr(
            $receipt,
            strpos($receipt, '\$receiptAfterPrintUrl = route('),
            240
        );
        self::assertStringNotContainsString("route('fiscal.show'", $calculation);
        self::assertStringContainsString('Imprimir comprovante interno', $receipt);
        self::assertStringContainsString('SEM VALOR FISCAL', $receipt);
        self::assertStringContainsString("route('pdv.nfce.danfe'", $receipt);
    }

    public function test_pdv_displays_non_authorized_receipt_status_without_leaving_cashier(): void
    {
        $this->actingAs($this->admin());
        $this->get(route('pdv.index'))->assertOk()->assertSee('pdvForm');

        $view = file_get_contents(resource_path('views/pdv/index.blade.php'));
        self::assertNotFalse($view);
        self::assertStringContainsString('data-system-notification', $view);
        self::assertStringContainsString("session('pdv_last_sale')", $view);
        self::assertStringContainsString("match(\$lastFiscalStatus)", $view);
        self::assertStringContainsString('Não repita a venda nem reenvie a nota.', $view);
        self::assertStringContainsString('NFC-e rejeitada.', $view);
        self::assertStringContainsString('A autorização ainda não foi confirmada.', $view);
        self::assertStringContainsString('Em contingência', $view);
    }

    public function test_credit_and_debit_require_explicit_integration_in_preflight_and_xml(): void
    {
        $preflight = file_get_contents(app_path('Services/Fiscal/NFCePreflightService.php'));
        $builder = file_get_contents(app_path('Services/Fiscal/NFCeIniBuilder.php'));
        self::assertNotFalse($preflight);
        self::assertNotFalse($builder);

        self::assertStringContainsString("in_array(\$kind, ['credit_card','debit_card'], true)", $preflight);
        self::assertStringContainsString("['1','2']", $preflight);
        self::assertStringContainsString('CNPJ e UF da transação', $preflight);
        self::assertStringContainsString('bandeira do cartão inválida', $preflight);
        self::assertStringContainsString("['1', '2']", $builder);
        self::assertStringContainsString("'tpIntegra' => \$integration", $builder);
        self::assertStringNotContainsString("'integration_type'] ?? '2'", $builder);
    }

    public function test_fiscal_receipt_never_fabricates_an_authorization(): void
    {
        $receipt = file_get_contents(resource_path('views/pdv/receipt.blade.php'));
        $pdv = file_get_contents(resource_path('views/pdv/index.blade.php'));
        self::assertNotFalse($receipt);
        self::assertNotFalse($pdv);

        self::assertStringContainsString("\$nfceDocument?->status==='authorized'", $receipt);
        self::assertStringContainsString("str_ends_with((string)\$nfceDocument->xml_path,'authorized.xml')", $receipt);
        self::assertStringContainsString("\$lastFiscalStatus==='authorized'", $pdv);
        self::assertStringContainsString("\$lastPdvNfceDocument?->protocol", $pdv);
    }
}
