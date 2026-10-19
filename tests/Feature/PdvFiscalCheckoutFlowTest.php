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

        $offset = strpos($receipt, '$receiptAfterPrintUrl = route(');
        self::assertNotFalse($offset);
        $calculation = substr($receipt, $offset, 240);
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
        self::assertStringContainsString('em contingência', $view);
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

    public function test_offline_danfe_is_distinct_from_internal_receipt_and_has_two_copies(): void
    {
        $receipt = file_get_contents(resource_path('views/pdv/receipt.blade.php'));
        $cashier = file_get_contents(resource_path('views/pdv/index.blade.php'));
        $danfe = file_get_contents(resource_path('views/fiscal/nfce-danfe.blade.php'));
        self::assertNotFalse($receipt);
        self::assertNotFalse($cashier);
        self::assertNotFalse($danfe);

        self::assertStringContainsString('DANFE contingência (2 vias)', $receipt);
        self::assertStringContainsString('DANFE contingência (2 vias)', $cashier);
        self::assertStringContainsString('offline_signed', $receipt);
        self::assertStringContainsString("'/signed.xml'", $receipt);
        self::assertStringContainsString("(!empty(\$danfe['offline'])?2:1)", $danfe);
        self::assertStringContainsString('PENDENTE DE AUTORIZAÇÃO', $danfe);
    }

    public function test_automatic_nfce_is_always_enqueued_on_database_after_sale_commit(): void
    {
        $service = file_get_contents(app_path('Services/SalesService.php'));
        $controller = file_get_contents(app_path('Http/Controllers/PdvController.php'));
        self::assertNotFalse($service);
        self::assertNotFalse($controller);

        self::assertStringContainsString("ProcessNFCeJob::dispatch(\$fiscalJob->id)->onConnection('database')", $service);
        self::assertStringNotContainsString("config('queue.default') === 'database'", $service);
        self::assertStringContainsString("->route('pdv.receipt',\$sale)", $controller);
    }

    public function test_pdv_card_editor_checks_integration_and_transaction_uf_before_submit(): void
    {
        $js = file_get_contents(public_path('js/pdv.js'));
        self::assertNotFalse($js);

        self::assertStringContainsString("['1','2'].includes(data.integration_type)", $js);
        self::assertStringContainsString('!!data.transaction_document !== !!data.transaction_state', $js);
        self::assertStringContainsString("validUFs.includes(data.transaction_state)", $js);
    }


    public function test_acbr_ini_maps_payment_cnpj_to_the_correct_fields(): void
    {
        $builder = file_get_contents(app_path('Services/Fiscal/NFCeIniBuilder.php'));
        self::assertNotFalse($builder);

        foreach ([
            "'CNPJPag' =>",
            "'UFPag' =>",
            "'CNPJReceb' =>",
            "'idTermPag' =>",
            "'CNPJ' =>",
            "'tPag' =>",
            "'cAut' =>",
            "'tpIntegra' =>",
        ] as $field) {
            self::assertStringContainsString($field, $builder);
        }
        self::assertStringContainsString('transaction_document', $builder);
        self::assertStringContainsString('transaction_state', $builder);
        self::assertStringContainsString('institution_document', $builder);
        self::assertStringContainsString('beneficiary_document', $builder);
        self::assertStringContainsString('terminal_id', $builder);
        self::assertStringNotContainsString("'CNPJ' => \$digits(\$payment['transaction_document']", $builder);
    }

    public function test_integrated_cards_need_acquirer_and_authorization_in_cashier_and_preflight(): void
    {
        $preflight = file_get_contents(app_path('Services/Fiscal/NFCePreflightService.php'));
        $js = file_get_contents(public_path('js/pdv.js'));
        $view = file_get_contents(resource_path('views/pdv/index.blade.php'));
        self::assertNotFalse($preflight);
        self::assertNotFalse($js);
        self::assertNotFalse($view);

        self::assertStringContainsString('cartão integrado exige CNPJ da adquirente e código de autorização.', $preflight);
        self::assertStringContainsString('Cartão integrado: informe o CNPJ da adquirente', $js);
        self::assertStringContainsString('Dados de cartão e PIX', $view);
        self::assertStringContainsString("const isCardKind=kind=>['card','credit_card','debit_card'].includes(kind);", $js);
        self::assertStringContainsString("isCardKind(paymentKind(payment.value))", $js);
    }

}
