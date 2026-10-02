<?php

namespace App\Fiscal\Soap;

use App\Fiscal\Sefaz\DTO\SefazEndpoint;
use App\Fiscal\Sefaz\Exceptions\SefazSoapException;
use DOMDocument;

final class Soap12EnvelopeBuilder
{
    public const NS = 'http://www.w3.org/2003/05/soap-envelope';

    public function build(SefazEndpoint $endpoint, string $payloadXml): string
    {
        $payload = new DOMDocument();
        $payload->preserveWhiteSpace = false;

        if (!@$payload->loadXML($payloadXml, LIBXML_NONET | LIBXML_NOBLANKS)) {
            throw new SefazSoapException('Payload fiscal não é XML bem-formado.');
        }

        $soap = new DOMDocument('1.0', 'UTF-8');
        $soap->formatOutput = false;
        $soap->preserveWhiteSpace = false;

        $envelope = $soap->createElementNS(self::NS, 'soap12:Envelope');
        $soap->appendChild($envelope);
        $body = $soap->createElementNS(self::NS, 'soap12:Body');
        $envelope->appendChild($body);

        $message = $soap->createElementNS($endpoint->wsdlNamespace, 'nfeDadosMsg');
        $body->appendChild($message);
        $message->appendChild($soap->importNode($payload->documentElement, true));

        $xml = $soap->saveXML();

        if (!is_string($xml) || $xml === '') {
            throw new SefazSoapException('Não foi possível serializar envelope SOAP 1.2.');
        }

        return $xml;
    }
}
