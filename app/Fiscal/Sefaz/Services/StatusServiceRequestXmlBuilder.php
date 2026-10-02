<?php

namespace App\Fiscal\Sefaz\Services;

use App\Fiscal\Sefaz\DTO\StatusServiceRequest;
use DOMDocument;

final class StatusServiceRequestXmlBuilder
{
    private const NFE_NS = 'http://www.portalfiscal.inf.br/nfe';

    public function build(StatusServiceRequest $request): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false;
        $dom->preserveWhiteSpace = false;

        $root = $dom->createElementNS(self::NFE_NS, 'consStatServ');
        $root->setAttribute('versao', $request->version);
        $dom->appendChild($root);

        $root->appendChild($dom->createElementNS(self::NFE_NS, 'tpAmb', $request->environment->value));
        $root->appendChild($dom->createElementNS(self::NFE_NS, 'cUF', $request->stateCode));
        $root->appendChild($dom->createElementNS(self::NFE_NS, 'xServ', 'STATUS'));

        $xml = $dom->saveXML($dom->documentElement);

        if (!is_string($xml) || $xml === '') {
            throw new \RuntimeException('Não foi possível serializar consStatServ.');
        }

        return $xml;
    }
}
