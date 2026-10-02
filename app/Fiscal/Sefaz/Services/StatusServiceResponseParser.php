<?php

namespace App\Fiscal\Sefaz\Services;

use App\Fiscal\Enums\FiscalEnvironment;
use App\Fiscal\Sefaz\DTO\StatusServiceResponse;
use App\Fiscal\Sefaz\Exceptions\SefazResponseException;
use DOMDocument;
use DOMElement;
use DOMXPath;

final class StatusServiceResponseParser
{
    private const NFE_NS = 'http://www.portalfiscal.inf.br/nfe';

    public function parse(string $xml): StatusServiceResponse
    {
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;

        if (!@$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
            throw new SefazResponseException('retConsStatServ não é XML bem-formado.');
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('nfe', self::NFE_NS);

        $root = $xpath->query('/nfe:retConsStatServ');
        if ($root === false || $root->length !== 1 || !$root->item(0) instanceof DOMElement) {
            throw new SefazResponseException(
                'Resposta fiscal não contém retConsStatServ no namespace oficial da NF-e.'
            );
        }

        /** @var DOMElement $rootElement */
        $rootElement = $root->item(0);
        if ($rootElement->getAttribute('versao') !== '4.00') {
            throw new SefazResponseException('Versão inesperada em retConsStatServ; esperado 4.00.');
        }

        $tpAmb = $this->required($xpath, 'tpAmb');
        $environment = FiscalEnvironment::tryFrom($tpAmb);
        if ($environment === null) {
            throw new SefazResponseException("tpAmb inválido retornado pela SEFAZ: {$tpAmb}.");
        }

        $cStat = $this->required($xpath, 'cStat');
        if (!preg_match('/^\d{3}$/', $cStat)) {
            throw new SefazResponseException('cStat retornado pela SEFAZ possui formato inválido.');
        }

        return new StatusServiceResponse(
            environment: $environment,
            applicationVersion: $this->required($xpath, 'verAplic'),
            stateCode: $this->required($xpath, 'cUF'),
            statusCode: $cStat,
            reason: $this->required($xpath, 'xMotivo'),
            receivedAt: $this->optional($xpath, 'dhRecbto'),
            averageTime: $this->optional($xpath, 'tMed'),
            returnAt: $this->optional($xpath, 'dhRetorno'),
            observation: $this->optional($xpath, 'xObs'),
            rawXmlHash: hash('sha256', $xml),
        );
    }

    private function required(DOMXPath $xpath, string $name): string
    {
        $value = trim((string) $xpath->evaluate("string(/nfe:retConsStatServ/nfe:{$name})"));

        if ($value === '') {
            throw new SefazResponseException("Campo obrigatório {$name} ausente em retConsStatServ.");
        }

        return $value;
    }

    private function optional(DOMXPath $xpath, string $name): ?string
    {
        $value = trim((string) $xpath->evaluate("string(/nfe:retConsStatServ/nfe:{$name})"));

        return $value === '' ? null : $value;
    }
}
