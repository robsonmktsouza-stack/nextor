<?php

namespace App\Fiscal\Soap;

use App\Fiscal\Sefaz\DTO\SefazEndpoint;
use App\Fiscal\Sefaz\Exceptions\SefazSoapException;
use DOMDocument;
use DOMElement;
use DOMXPath;

final class Soap12ResponseParser
{
    public function extractResult(SefazEndpoint $endpoint, string $soapXml): string
    {
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;

        if (!@$dom->loadXML($soapXml, LIBXML_NONET | LIBXML_NOBLANKS)) {
            throw new SefazSoapException('Resposta SOAP não é XML bem-formado.');
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('soap12', Soap12EnvelopeBuilder::NS);
        $xpath->registerNamespace('ws', $endpoint->wsdlNamespace);

        $fault = $xpath->query('/soap12:Envelope/soap12:Body/soap12:Fault');
        if ($fault !== false && $fault->length > 0) {
            $reason = trim((string) $xpath->evaluate('string(/soap12:Envelope/soap12:Body/soap12:Fault/soap12:Reason/soap12:Text)'));
            throw new SefazSoapException(
                $reason !== '' ? "SEFAZ retornou SOAP Fault: {$reason}" : 'SEFAZ retornou SOAP Fault.'
            );
        }

        $result = $xpath->query('/soap12:Envelope/soap12:Body/ws:nfeResultMsg');
        if ($result === false || $result->length !== 1 || !$result->item(0) instanceof DOMElement) {
            throw new SefazSoapException('Resposta SOAP não contém nfeResultMsg no namespace esperado.');
        }

        /** @var DOMElement $resultElement */
        $resultElement = $result->item(0);

        foreach ($resultElement->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $xml = $dom->saveXML($child);

                if (is_string($xml) && $xml !== '') {
                    return $xml;
                }
            }
        }

        $text = trim($resultElement->textContent);
        if ($text !== '' && str_starts_with($text, '<')) {
            $inner = new DOMDocument();
            if (@$inner->loadXML($text, LIBXML_NONET | LIBXML_NOBLANKS)) {
                $xml = $inner->saveXML($inner->documentElement);
                if (is_string($xml) && $xml !== '') {
                    return $xml;
                }
            }
        }

        throw new SefazSoapException('nfeResultMsg não contém retorno fiscal XML utilizável.');
    }
}
