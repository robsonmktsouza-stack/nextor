<?php

namespace Tests\Feature;

use App\Fiscal\Certificate\A1CertificateReader;
use App\Fiscal\Certificate\A1CertificateVault;
use App\Fiscal\Contracts\FiscalEngineInterface;
use App\Fiscal\Enums\FiscalDocumentState;
use App\Fiscal\Enums\FiscalEnvironment;
use App\Fiscal\Exceptions\CertificateException;
use App\Fiscal\Exceptions\ImmutableFiscalDocumentException;
use App\Fiscal\Models\FiscalCertificate;
use App\Fiscal\Models\FiscalCompany;
use App\Fiscal\Nfce\FiscalSequenceService;
use App\Fiscal\Schema\SchemaValidator;
use App\Fiscal\Signature\A1SigningMaterialProvider;
use App\Fiscal\Signature\XmlSignatureException;
use App\Fiscal\Signature\XmlSignatureVerifier;
use App\Fiscal\Tax\DTO\FiscalPaymentInput;
use App\Fiscal\Tax\DTO\NfceRecipientInput;
use App\Fiscal\Tax\DTO\NfceTaxDocumentInput;
use App\Fiscal\Tax\DTO\TaxItemInput;
use App\Fiscal\Tax\Enums\FiscalPaymentMethod;
use App\Fiscal\Tax\Models\FiscalTaxGroup;
use App\Fiscal\Tax\Models\FiscalTaxRule;
use App\Fiscal\Tax\ValueObjects\Money;
use App\Fiscal\Tax\ValueObjects\Quantity;
use App\Fiscal\Tax\ValueObjects\UnitPrice;
use App\Models\Product;
use DateTimeImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestA1CertificateFactory;
use Tests\TestCase;

class FiscalSignatureEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_test_pfx_can_be_read_and_wrong_password_is_rejected(): void
    {
        $fixture = TestA1CertificateFactory::make();

        $result = app(A1CertificateReader::class)->read(
            $fixture['pfx'],
            $fixture['password'],
        );

        $this->assertNotNull($result['info']->validTo);
        $this->assertNotNull($result['certificate']);
        $this->assertNotNull($result['private_key']);

        $this->expectException(CertificateException::class);
        app(A1CertificateReader::class)->read($fixture['pfx'], 'wrong-password');
    }

    public function test_expired_certificate_record_is_rejected_before_private_key_use(): void
    {
        $company = $this->company();
        $fixture = TestA1CertificateFactory::make();

        FiscalCertificate::query()->create([
            'fiscal_company_id' => $company->id,
            'pfx_payload' => base64_encode($fixture['pfx']),
            'pfx_password' => $fixture['password'],
            'valid_from' => now()->subDays(10),
            'valid_to' => now()->subDay(),
            'is_active' => true,
        ]);

        $this->expectException(XmlSignatureException::class);
        $this->expectExceptionMessage('expirado');

        app(A1SigningMaterialProvider::class)->forCompany($company);
    }

    public function test_full_engine_generates_signs_verifies_and_validates_official_xsd(): void
    {
        [$company, $product] = $this->configuredScenario();
        $fixture = TestA1CertificateFactory::make();

        app(A1CertificateVault::class)->store(
            $company,
            $fixture['pfx'],
            $fixture['password'],
        );

        app(FiscalSequenceService::class)->ensure(
            $company,
            1,
            FiscalEnvironment::HOMOLOGATION,
        );

        /** @var FiscalEngineInterface $engine */
        $engine = app(FiscalEngineInterface::class);

        $document = $engine->createDocument(
            company: $company,
            input: $this->input($product),
            series: 1,
            issueAt: new DateTimeImmutable('2026-10-01T12:00:00-03:00'),
        );

        $this->assertSame(FiscalDocumentState::DRAFT, $document->state);

        $document = $engine->generate($document);
        $unsignedXml = $document->xml_generated;

        $this->assertSame(FiscalDocumentState::GENERATED, $document->state);
        $this->assertNotNull($unsignedXml);

        $unsignedSchema = app(SchemaValidator::class)->validate($unsignedXml, 'NFe', '4.00');
        $this->assertFalse($unsignedSchema->valid);

        $document = $engine->sign($document);

        $this->assertSame(FiscalDocumentState::SIGNED, $document->state);
        $this->assertSame($unsignedXml, $document->xml_generated);
        $this->assertNotNull($document->xml_signed);
        $this->assertStringNotContainsString('PRIVATE KEY', $document->xml_signed);
        $this->assertStringNotContainsString($fixture['password'], $document->xml_signed);

        $dom = new DOMDocument();
        $this->assertTrue($dom->loadXML($document->xml_signed));
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('nfe', 'http://www.portalfiscal.inf.br/nfe');
        $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');

        $reference = $xpath->evaluate('string(/nfe:NFe/ds:Signature/ds:SignedInfo/ds:Reference/@URI)');
        $digest = $xpath->evaluate('string(/nfe:NFe/ds:Signature/ds:SignedInfo/ds:Reference/ds:DigestValue)');
        $signatureValue = $xpath->evaluate('string(/nfe:NFe/ds:Signature/ds:SignatureValue)');
        $certificate = $xpath->evaluate('string(/nfe:NFe/ds:Signature/ds:KeyInfo/ds:X509Data/ds:X509Certificate)');

        $this->assertSame('#NFe'.$document->access_key, $reference);
        $this->assertNotSame('', $digest);
        $this->assertNotSame('', $signatureValue);
        $this->assertNotSame('', $certificate);
        $this->assertStringNotContainsString('BEGIN CERTIFICATE', $certificate);

        $signatureCheck = app(XmlSignatureVerifier::class)->verify(
            $document->xml_signed,
            (string) $document->access_key,
        );
        $this->assertTrue($signatureCheck->valid, implode(' | ', $signatureCheck->errors));

        $validation = $engine->validate($document);

        $this->assertTrue(
            $validation->schema->valid,
            implode(' | ', array_map(static fn ($e) => $e->message, $validation->schema->errors)),
        );
        $this->assertTrue($validation->signature->valid, implode(' | ', $validation->signature->errors));
        $this->assertTrue($validation->valid);
        $this->assertSame(FiscalDocumentState::VALIDATED, $document->refresh()->state);
        $this->assertSame([], libxml_get_errors());
    }

    public function test_tampering_with_product_after_signature_invalidates_digest(): void
    {
        [$company, $product] = $this->configuredScenario();
        $fixture = TestA1CertificateFactory::make();

        app(A1CertificateVault::class)->store($company, $fixture['pfx'], $fixture['password']);
        app(FiscalSequenceService::class)->ensure($company, 1, FiscalEnvironment::HOMOLOGATION);

        /** @var FiscalEngineInterface $engine */
        $engine = app(FiscalEngineInterface::class);

        $document = $engine->createDocument(
            $company,
            $this->input($product),
            issueAt: new DateTimeImmutable('2026-10-01T12:00:00-03:00'),
        );
        $document = $engine->generate($document);
        $document = $engine->sign($document);

        $tampered = str_replace('FITA TESTE', 'FITA ALTERADA', $document->xml_signed);

        $result = app(XmlSignatureVerifier::class)->verify(
            $tampered,
            (string) $document->access_key,
        );

        $this->assertFalse($result->valid);
        $this->assertStringContainsString('DigestValue', implode(' | ', $result->errors));
    }

    public function test_failed_local_validation_moves_signed_document_to_error_and_blocks_regeneration(): void
    {
        [$company, $product] = $this->configuredScenario();
        $fixture = TestA1CertificateFactory::make();

        app(A1CertificateVault::class)->store($company, $fixture['pfx'], $fixture['password']);
        app(FiscalSequenceService::class)->ensure($company, 1, FiscalEnvironment::HOMOLOGATION);

        /** @var FiscalEngineInterface $engine */
        $engine = app(FiscalEngineInterface::class);
        $document = $engine->generate($engine->createDocument(
            $company,
            $this->input($product),
            issueAt: new DateTimeImmutable('2026-10-01T12:00:00-03:00'),
        ));
        $document = $engine->sign($document);

        $tampered = str_replace('FITA TESTE', 'FITA ALTERADA', (string) $document->xml_signed);

        \Illuminate\Support\Facades\DB::table('fiscal_documents')
            ->where('id', $document->id)
            ->update(['xml_signed' => $tampered]);

        $document->refresh();
        $result = $engine->validate($document);

        $this->assertFalse($result->valid);
        $this->assertSame(FiscalDocumentState::ERROR, $document->refresh()->state);
        $this->assertSame($tampered, $document->xml_signed);

        $this->expectException(ImmutableFiscalDocumentException::class);
        $engine->generate($document);
    }

    public function test_signed_xml_is_write_once(): void
    {
        [$company, $product] = $this->configuredScenario();
        $fixture = TestA1CertificateFactory::make();

        app(A1CertificateVault::class)->store($company, $fixture['pfx'], $fixture['password']);
        app(FiscalSequenceService::class)->ensure($company, 1, FiscalEnvironment::HOMOLOGATION);

        /** @var FiscalEngineInterface $engine */
        $engine = app(FiscalEngineInterface::class);
        $document = $engine->generate($engine->createDocument(
            $company,
            $this->input($product),
            issueAt: new DateTimeImmutable('2026-10-01T12:00:00-03:00'),
        ));
        $document = $engine->sign($document);

        $document->xml_signed = '<changed/>';

        $this->expectException(ImmutableFiscalDocumentException::class);
        $document->save();
    }

    private function configuredScenario(): array
    {
        $company = $this->company();

        $group = FiscalTaxGroup::query()->create([
            'code' => 'SN-102-NORMAL',
            'name' => 'SN venda interna normal',
            'crt' => '1',
            'model' => '65',
            'is_active' => true,
        ]);

        FiscalTaxRule::query()->create([
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
        ]);

        $product = Product::query()->create([
            'sku' => 'P001',
            'name' => 'FITA TESTE',
            'unit' => 'UN',
            'sale_price' => '10.00',
            'origin' => '0',
            'ncm' => '96121000',
            'tax_group' => 'SN-102-NORMAL',
            'different_tax_unit' => false,
            'is_active' => true,
        ]);

        return [$company, $product];
    }

    private function company(): FiscalCompany
    {
        return FiscalCompany::query()->create([
            'legal_name' => 'Nextor Teste Ltda',
            'trade_name' => 'Nextor Teste',
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
    }

    private function input(Product $product): NfceTaxDocumentInput
    {
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
                    FiscalPaymentMethod::CASH,
                    new Money('10.00'),
                ),
            ],
            processVersion: 'Nextor 1.0',
            recipient: new NfceRecipientInput(
                documentType: 'CPF',
                document: '12345678909',
                ieIndicator: '9',
                name: 'CONSUMIDOR TESTE',
            ),
        );
    }
}
