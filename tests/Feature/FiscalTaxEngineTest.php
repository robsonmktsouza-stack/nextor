<?php

namespace Tests\Feature;

use App\Fiscal\Enums\FiscalEnvironment;
use App\Fiscal\Models\FiscalCompany;
use App\Fiscal\Tax\DTO\FiscalPaymentInput;
use App\Fiscal\Tax\DTO\NfceTaxDocumentInput;
use App\Fiscal\Tax\DTO\PaymentCardInput;
use App\Fiscal\Tax\DTO\TaxItemInput;
use App\Fiscal\Tax\Enums\FiscalPaymentMethod;
use App\Fiscal\Tax\Enums\PaymentIntegrationType;
use App\Fiscal\Tax\Exceptions\InvalidDecimalException;
use App\Fiscal\Tax\Exceptions\TaxConfigurationException;
use App\Fiscal\Tax\Models\FiscalTaxGroup;
use App\Fiscal\Tax\Models\FiscalTaxRule;
use App\Fiscal\Tax\Resolver\SimpleNationalRetailTaxEngine;
use App\Fiscal\Tax\ValueObjects\Money;
use App\Fiscal\Tax\ValueObjects\Quantity;
use App\Fiscal\Tax\ValueObjects\UnitPrice;
use App\Models\Product;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiscalTaxEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_supported_scenario_resolves_icms_pis_cofins_and_rtc(): void
    {
        [$company, $product] = $this->configuredScenario();

        $result = app(SimpleNationalRetailTaxEngine::class)->resolve(
            $company,
            $this->input($product, FiscalPaymentMethod::CASH, '10.00'),
            new DateTimeImmutable('2026-10-01T12:00:00-03:00'),
        );

        $snapshot = $result->toResolvedSnapshot($this->input(
            $product,
            FiscalPaymentMethod::CASH,
            '10.00',
        ));

        $tax = $snapshot['items'][0]['tax'];

        $this->assertSame('102', $tax['ICMS']['ICMSSN102']['CSOSN']);
        $this->assertSame('49', $tax['PIS']['PISOutr']['CST']);
        $this->assertSame('49', $tax['COFINS']['COFINSOutr']['CST']);
        $this->assertSame('000', $tax['IBSCBS']['CST']);
        $this->assertSame('000001', $tax['IBSCBS']['cClassTrib']);
        $this->assertSame('0.1000', $tax['IBSCBS']['gIBSCBS']['gIBSUF']['pIBSUF']);
        $this->assertSame('0.0000', $tax['IBSCBS']['gIBSCBS']['gIBSMun']['pIBSMun']);
        $this->assertSame('0.9000', $tax['IBSCBS']['gIBSCBS']['gCBS']['pCBS']);
        $this->assertSame('10.00', $snapshot['totals']['ICMSTot']['vNF']);
        $this->assertSame('10.00', $snapshot['items'][0]['total_item']);
        $this->assertSame('10.00', $snapshot['totals']['vNFTot']);
    }

    public function test_money_rejects_float(): void
    {
        $this->expectException(InvalidDecimalException::class);
        new Money(10.50);
    }

    public function test_product_without_ncm_is_rejected(): void
    {
        [$company, $product] = $this->configuredScenario(['ncm' => null]);

        $this->expectException(TaxConfigurationException::class);
        $this->expectExceptionMessage('não possui NCM');

        app(SimpleNationalRetailTaxEngine::class)->resolve(
            $company,
            $this->input($product),
            new DateTimeImmutable('2026-10-01'),
        );
    }

    public function test_product_without_tax_group_is_rejected(): void
    {
        [$company, $product] = $this->configuredScenario(['tax_group' => null]);

        $this->expectException(TaxConfigurationException::class);
        $this->expectExceptionMessage('não possui grupo tributário');

        app(SimpleNationalRetailTaxEngine::class)->resolve(
            $company,
            $this->input($product),
            new DateTimeImmutable('2026-10-01'),
        );
    }

    public function test_invalid_internal_cfop_is_rejected(): void
    {
        [$company, $product] = $this->configuredScenario([], ['cfop' => '6102']);

        $this->expectException(TaxConfigurationException::class);
        $this->expectExceptionMessage('CFOP interno');

        app(SimpleNationalRetailTaxEngine::class)->resolve(
            $company,
            $this->input($product),
            new DateTimeImmutable('2026-10-01'),
        );
    }

    public function test_invalid_origin_is_rejected(): void
    {
        [$company, $product] = $this->configuredScenario(['origin' => '9']);

        $this->expectException(TaxConfigurationException::class);
        $this->expectExceptionMessage('origem fiscal');

        app(SimpleNationalRetailTaxEngine::class)->resolve(
            $company,
            $this->input($product),
            new DateTimeImmutable('2026-10-01'),
        );
    }

    public function test_known_but_unsupported_csosn_is_rejected(): void
    {
        [$company, $product] = $this->configuredScenario([], ['icms_csosn' => '101']);

        $this->expectException(TaxConfigurationException::class);
        $this->expectExceptionMessage('ainda não é suportado');

        app(SimpleNationalRetailTaxEngine::class)->resolve(
            $company,
            $this->input($product),
            new DateTimeImmutable('2026-10-01'),
        );
    }

    public function test_pis_configuration_is_not_inferred(): void
    {
        [$company, $product] = $this->configuredScenario([], ['pis_cst' => '01']);

        $this->expectException(TaxConfigurationException::class);
        $this->expectExceptionMessage('PIS CST');

        app(SimpleNationalRetailTaxEngine::class)->resolve(
            $company,
            $this->input($product),
            new DateTimeImmutable('2026-10-01'),
        );
    }

    public function test_cofins_configuration_is_not_inferred(): void
    {
        [$company, $product] = $this->configuredScenario([], ['cofins_cst' => '01']);

        $this->expectException(TaxConfigurationException::class);
        $this->expectExceptionMessage('COFINS CST');

        app(SimpleNationalRetailTaxEngine::class)->resolve(
            $company,
            $this->input($product),
            new DateTimeImmutable('2026-10-01'),
        );
    }

    public function test_wrong_2026_rtc_rate_is_rejected(): void
    {
        [$company, $product] = $this->configuredScenario([], ['cbs_rate' => '1.0000']);

        $this->expectException(TaxConfigurationException::class);
        $this->expectExceptionMessage('pCBS');

        app(SimpleNationalRetailTaxEngine::class)->resolve(
            $company,
            $this->input($product),
            new DateTimeImmutable('2026-10-01'),
        );
    }

    public function test_unknown_cclass_trib_is_rejected(): void
    {
        [$company, $product] = $this->configuredScenario([], [
            'ibs_cst' => '999',
            'ibs_classification' => '999999',
        ]);

        $this->expectException(TaxConfigurationException::class);
        $this->expectExceptionMessage('catálogo versionado');

        app(SimpleNationalRetailTaxEngine::class)->resolve(
            $company,
            $this->input($product),
            new DateTimeImmutable('2026-10-01'),
        );
    }

    public function test_cash_pix_and_card_use_official_tpag_mapping_without_fake_acquirer_data(): void
    {
        $cash = new FiscalPaymentInput(FiscalPaymentMethod::CASH, new Money('1.00'));
        $pix = new FiscalPaymentInput(FiscalPaymentMethod::PIX, new Money('1.00'));
        $card = new FiscalPaymentInput(
            FiscalPaymentMethod::CREDIT_CARD,
            new Money('1.00'),
            card: new PaymentCardInput(PaymentIntegrationType::NOT_INTEGRATED),
        );

        $this->assertSame('01', $cash->toXmlArray()['type']);
        $this->assertSame('17', $pix->toXmlArray()['type']);
        $this->assertSame('03', $card->toXmlArray()['type']);
        $this->assertSame(['tpIntegra' => '2'], $card->toXmlArray()['card']);
    }

    private function configuredScenario(array $productOverrides = [], array $ruleOverrides = []): array
    {
        $company = FiscalCompany::query()->create([
            'legal_name' => 'Nextor Teste Ltda',
            'cnpj' => '12345678000195',
            'state_registration' => '123456789',
            'crt' => '1',
            'uf' => 'SP',
            'city_ibge' => '3550308',
            'street' => 'Rua Teste',
            'number' => '100',
            'district' => 'Centro',
            'city' => 'Sao Paulo',
            'zip_code' => '01001000',
            'environment' => FiscalEnvironment::HOMOLOGATION,
            'production_enabled' => false,
            'is_active' => true,
        ]);

        $group = FiscalTaxGroup::query()->create([
            'code' => 'SN-102-NORMAL',
            'name' => 'SN venda interna normal',
            'crt' => '1',
            'model' => '65',
            'is_active' => true,
        ]);

        FiscalTaxRule::query()->create(array_merge([
            'fiscal_tax_group_id' => $group->id,
            'operation_scope' => 'internal_final_consumer_present',
            'cfop' => '5102',
            'icms_csosn' => '102',
            'pis_cst' => '49',
            'pis_rate' => '0.0000',
            'pis_base_mode' => 'item_operation_value',
            'cofins_cst' => '49',
            'cofins_rate' => '0.0000',
            'cofins_base_mode' => 'item_operation_value',
            'rtc_mode' => 'required',
            'ibs_cst' => '000',
            'ibs_classification' => '000001',
            'ibs_uf_rate' => '0.1000',
            'ibs_mun_rate' => '0.0000',
            'cbs_rate' => '0.9000',
            'ibs_cbs_base_mode' => 'nt2025_002_ub16_2026',
            'rule_version' => '2026.10',
            'effective_from' => '2026-08-03',
            'is_active' => true,
        ], $ruleOverrides));

        $product = Product::query()->create(array_merge([
            'sku' => 'P001',
            'name' => 'FITA TESTE',
            'unit' => 'UN',
            'sale_price' => '10.00',
            'origin' => '0',
            'ncm' => '96121000',
            'tax_group' => 'SN-102-NORMAL',
            'different_tax_unit' => false,
            'is_active' => true,
        ], $productOverrides));

        return [$company, $product];
    }

    private function input(
        Product $product,
        FiscalPaymentMethod $method = FiscalPaymentMethod::CASH,
        string $payment = '10.00',
    ): NfceTaxDocumentInput {
        $card = in_array($method, [FiscalPaymentMethod::CREDIT_CARD, FiscalPaymentMethod::DEBIT_CARD], true)
            ? new PaymentCardInput(PaymentIntegrationType::NOT_INTEGRATED)
            : null;

        return new NfceTaxDocumentInput(
            natureOperation: 'VENDA',
            cityTaxCode: '3550308',
            items: [
                new TaxItemInput(
                    product: $product,
                    quantity: new Quantity('1.0000'),
                    unitPrice: new UnitPrice('10.0000000000'),
                ),
            ],
            payments: [
                new FiscalPaymentInput(
                    method: $method,
                    amount: new Money($payment),
                    card: $card,
                ),
            ],
            processVersion: 'Nextor 1.0',
        );
    }
}
