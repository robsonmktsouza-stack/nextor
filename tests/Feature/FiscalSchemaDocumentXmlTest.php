<?php

namespace Tests\Feature;

use App\Fiscal\DTO\NfceSnapshot;
use App\Fiscal\Enums\FiscalDocumentState;
use App\Fiscal\Enums\FiscalEnvironment;
use App\Fiscal\Exceptions\ImmutableFiscalDocumentException;
use App\Fiscal\Exceptions\InvalidFiscalDataException;
use App\Fiscal\Models\FiscalCompany;
use App\Fiscal\Nfce\FiscalDocumentCreator;
use App\Fiscal\Nfce\FiscalSequenceService;
use App\Fiscal\Schema\SchemaRegistry;
use App\Fiscal\Schema\SchemaValidator;
use App\Fiscal\Xml\NfceXmlService;
use App\Models\Sale;
use DateTimeImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiscalSchemaDocumentXmlTest extends TestCase
{
    use RefreshDatabase;

    public function test_official_nfe_400_schema_package_resolves_with_integrity_check(): void
    {
        $schema = app(SchemaRegistry::class)->resolve('NFe', '4.00');

        $this->assertSame('PL_010f_v1.04', $schema->manifest->package);
        $this->assertSame('nfe_v4.00.xsd', basename($schema->rootPath));
        $this->assertFileExists($schema->rootPath);
    }

    public function test_snapshot_rejects_float_values(): void
    {
        $company = $this->company();
        $data = $this->resolvedSnapshot();
        $data['items'][0]['unit_price'] = 10.0;

        $this->expectException(InvalidFiscalDataException::class);
        $this->expectExceptionMessage('float não é permitido');

        NfceSnapshot::fromResolvedData(
            $company,
            $data,
            new DateTimeImmutable('2026-10-01T12:00:00-03:00'),
        );
    }

    public function test_document_creation_reserves_number_links_sale_and_keeps_snapshot_immutable(): void
    {
        $company = $this->company();
        app(FiscalSequenceService::class)->ensure(
            $company,
            1,
            FiscalEnvironment::HOMOLOGATION,
        );

        $sale = Sale::query()->create([
            'status' => 'completed',
            'total' => '10.00',
        ]);

        $document = app(FiscalDocumentCreator::class)->create(
            company: $company,
            resolvedSnapshot: $this->resolvedSnapshot(),
            sale: $sale,
            series: 1,
            issueAt: new DateTimeImmutable('2026-10-01T12:00:00-03:00'),
        );

        $this->assertSame(FiscalDocumentState::DRAFT, $document->state);
        $this->assertSame(1, $document->number);
        $this->assertSame(44, strlen((string) $document->access_key));
        $this->assertTrue($document->snapshotIntegrityIsValid());
        $this->assertSame($document->id, $sale->fresh()->fiscalDocuments()->first()->id);
        $this->assertSame($document->id, $company->fresh()->documents()->first()->id);

        $document->number = 2;

        $this->expectException(ImmutableFiscalDocumentException::class);
        $document->save();
    }

    public function test_xml_generator_builds_nfce_400_without_signature_or_qrcode(): void
    {
        $document = $this->createDocument();

        $document = app(NfceXmlService::class)->generate($document);

        $this->assertSame(FiscalDocumentState::GENERATED, $document->state);
        $this->assertNotNull($document->xml_generated);

        $dom = new DOMDocument();
        $this->assertTrue($dom->loadXML($document->xml_generated));

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('nfe', 'http://www.portalfiscal.inf.br/nfe');
        $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');

        $this->assertSame('65', $xpath->evaluate('string(/nfe:NFe/nfe:infNFe/nfe:ide/nfe:mod)'));
        $this->assertSame(
            'NFe'.$document->access_key,
            $xpath->evaluate('string(/nfe:NFe/nfe:infNFe/@Id)')
        );
        $this->assertSame(0, $xpath->query('//ds:Signature')->length);
        $this->assertSame(0, $xpath->query('//nfe:infNFeSupl')->length);
    }

    public function test_official_xsd_reports_missing_signature_for_unsigned_phase_xml_and_clears_libxml_errors(): void
    {
        $document = app(NfceXmlService::class)->generate($this->createDocument());

        $result = app(SchemaValidator::class)->validate(
            $document->xml_generated,
            'NFe',
            '4.00',
        );

        $this->assertFalse($result->valid);
        $this->assertNotEmpty($result->errors);
        $this->assertStringContainsString(
            'Signature',
            implode(' | ', array_map(
                static fn ($error) => $error->message,
                $result->errors,
            )),
        );
        $this->assertSame([], libxml_get_errors());
    }

    public function test_schema_validator_returns_structured_parse_errors(): void
    {
        $result = app(SchemaValidator::class)->validate(
            '<NFe><broken></NFe>',
            'NFe',
            '4.00',
        );

        $this->assertFalse($result->valid);
        $this->assertNotEmpty($result->errors);
        $this->assertContains($result->errors[0]->level, ['warning', 'error', 'fatal', 'unknown']);
        $this->assertIsInt($result->errors[0]->code);
        $this->assertIsInt($result->errors[0]->line);
        $this->assertIsInt($result->errors[0]->column);
        $this->assertNotSame('', $result->errors[0]->message);
    }

    private function createDocument()
    {
        $company = $this->company();
        app(FiscalSequenceService::class)->ensure(
            $company,
            1,
            FiscalEnvironment::HOMOLOGATION,
        );

        return app(FiscalDocumentCreator::class)->create(
            company: $company,
            resolvedSnapshot: $this->resolvedSnapshot(),
            series: 1,
            issueAt: new DateTimeImmutable('2026-10-01T12:00:00-03:00'),
        );
    }

    private function company(): FiscalCompany
    {
        return FiscalCompany::query()->create([
            'legal_name' => 'Empresa Homologacao Ltda',
            'trade_name' => 'Empresa Teste',
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

    private function resolvedSnapshot(): array
    {
        return [
            'identification' => [
                'nature_operation' => 'VENDA',
                'operation_type' => '1',
                'destination' => '1',
                'city_tax_code' => '3550308',
                'print_type' => '4',
                'purpose' => '1',
                'final_consumer' => '1',
                'presence' => '1',
                'process' => '0',
                'process_version' => 'Nextor 1.0',
            ],
            'items' => [[
                'code' => 'P001',
                'gtin' => 'SEM GTIN',
                'description' => 'PRODUTO TESTE',
                'ncm' => '00000000',
                'cfop' => '5102',
                'unit' => 'UN',
                'quantity' => '1.0000',
                'unit_price' => '10.0000000000',
                'gross_total' => '10.00',
                'tax_gtin' => 'SEM GTIN',
                'tax_unit' => 'UN',
                'tax_quantity' => '1.0000',
                'tax_unit_price' => '10.0000000000',
                'include_total' => '1',
                'tax' => [
                    'ICMS' => [
                        'ICMSSN102' => [
                            'orig' => '0',
                            'CSOSN' => '102',
                        ],
                    ],
                    'PIS' => [
                        'PISOutr' => [
                            'CST' => '49',
                            'vBC' => '10.00',
                            'pPIS' => '0.0000',
                            'vPIS' => '0.00',
                        ],
                    ],
                    'COFINS' => [
                        'COFINSOutr' => [
                            'CST' => '49',
                            'vBC' => '10.00',
                            'pCOFINS' => '0.0000',
                            'vCOFINS' => '0.00',
                        ],
                    ],
                    'IBSCBS' => [
                        'CST' => '000',
                        'cClassTrib' => '000001',
                        'gIBSCBS' => [
                            'vBC' => '10.00',
                            'gIBSUF' => [
                                'pIBSUF' => '0.1000',
                                'vIBSUF' => '0.01',
                            ],
                            'gIBSMun' => [
                                'pIBSMun' => '0.0000',
                                'vIBSMun' => '0.00',
                            ],
                            'vIBS' => '0.01',
                            'gCBS' => [
                                'pCBS' => '0.9000',
                                'vCBS' => '0.09',
                            ],
                        ],
                    ],
                ],
                'total_item' => '10.00',
            ]],
            'totals' => [
                'ICMSTot' => [
                    'vBC' => '0.00',
                    'vICMS' => '0.00',
                    'vICMSDeson' => '0.00',
                    'vFCP' => '0.00',
                    'vBCST' => '0.00',
                    'vST' => '0.00',
                    'vFCPST' => '0.00',
                    'vFCPSTRet' => '0.00',
                    'vProd' => '10.00',
                    'vFrete' => '0.00',
                    'vSeg' => '0.00',
                    'vDesc' => '0.00',
                    'vII' => '0.00',
                    'vIPI' => '0.00',
                    'vIPIDevol' => '0.00',
                    'vPIS' => '0.00',
                    'vCOFINS' => '0.00',
                    'vOutro' => '0.00',
                    'vNF' => '10.00',
                ],
                'IBSCBSTot' => [
                    'vBCIBSCBS' => '10.00',
                    'gIBS' => [
                        'gIBSUF' => [
                            'vDif' => '0.00',
                            'vDevTrib' => '0.00',
                            'vIBSUF' => '0.01',
                        ],
                        'gIBSMun' => [
                            'vDif' => '0.00',
                            'vDevTrib' => '0.00',
                            'vIBSMun' => '0.00',
                        ],
                        'vIBS' => '0.01',
                        'vCredPres' => '0.00',
                        'vCredPresCondSus' => '0.00',
                    ],
                    'gCBS' => [
                        'vDif' => '0.00',
                        'vDevTrib' => '0.00',
                        'vCBS' => '0.09',
                        'vCredPres' => '0.00',
                        'vCredPresCondSus' => '0.00',
                    ],
                ],
                'vNFTot' => '10.00',
            ],
            'freight_mode' => '9',
            'payments' => [[
                'type' => '01',
                'amount' => '10.00',
            ]],
        ];
    }
}
