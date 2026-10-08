<?php

namespace Tests\Unit;

use App\Models\FiscalDocumentJob;
use App\Services\Fiscal\NFCeProtocolXmlService;
use DOMDocument;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NFCeProtocolXmlServiceTest extends TestCase
{
    private function signedXml(): string
    {
        $key = str_repeat('1', 44);
        return '<?xml version="1.0" encoding="UTF-8"?>'.
            '<NFe xmlns="http://www.portalfiscal.inf.br/nfe">'.
            '<infNFe Id="NFe'.$key.'" versao="4.00">'.
            '<ide><mod>65</mod><tpAmb>2</tpAmb><serie>1</serie><nNF>7</nNF></ide>'.
            '</infNFe>'.
            '<Signature xmlns="http://www.w3.org/2000/09/xmldsig#"><SignedInfo/></Signature>'.
            '</NFe>';
    }

    public function test_signed_xml_must_match_the_reserved_nfce(): void
    {
        $job = new FiscalDocumentJob([
            'document_type' => 'nfce', 'environment' => 'homologation',
            'series' => 1, 'document_number' => 7,
        ]);
        $service = new NFCeProtocolXmlService();

        self::assertSame(str_repeat('1', 44), $service->verifySigned($this->signedXml(), $job));
    }

    public function test_rejects_xml_from_another_document_number(): void
    {
        $job = new FiscalDocumentJob([
            'document_type' => 'nfce', 'environment' => 'homologation',
            'series' => 1, 'document_number' => 8,
        ]);
        $this->expectException(RuntimeException::class);
        (new NFCeProtocolXmlService())->verifySigned($this->signedXml(), $job);
    }

    public function test_builds_nfeproc_only_with_real_matching_sefaz_protocol_fields(): void
    {
        $service = new NFCeProtocolXmlService();
        $xml = $service->buildAuthorized($this->signedXml(), [
            'individual' => true,
            'cstat' => '100',
            'reason' => 'Autorizado o uso da NF-e',
            'key' => str_repeat('1', 44),
            'protocol' => str_repeat('2', 15),
            'received_at' => '2026-10-08T12:15:00-03:00',
            'digest' => 'BASE64DIGEST=',
            'environment' => '2',
            'application' => 'BA_TESTE',
        ]);

        $document = new DOMDocument();
        self::assertTrue($document->loadXML($xml));
        self::assertSame('nfeProc', $document->documentElement->localName);
        self::assertSame(1, $document->getElementsByTagNameNS('http://www.portalfiscal.inf.br/nfe', 'protNFe')->length);
        self::assertSame(str_repeat('2', 15), $document->getElementsByTagNameNS('http://www.portalfiscal.inf.br/nfe', 'nProt')->item(0)->textContent);
    }

    public function test_rejects_a_protocol_for_another_access_key(): void
    {
        $this->expectException(RuntimeException::class);
        (new NFCeProtocolXmlService())->buildAuthorized($this->signedXml(), [
            'individual' => true,
            'cstat' => '100',
            'reason' => 'Autorizado',
            'key' => str_repeat('9', 44),
            'protocol' => str_repeat('2', 15),
            'received_at' => '2026-10-08T12:15:00-03:00',
            'digest' => 'BASE64DIGEST=',
            'environment' => '2',
            'application' => 'BA_TESTE',
        ]);
    }
}
