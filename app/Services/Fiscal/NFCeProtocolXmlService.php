<?php

namespace App\Services\Fiscal;

use App\Models\FiscalDocumentJob;
use DOMDocument;
use DOMElement;
use RuntimeException;

final class NFCeProtocolXmlService
{
    private const NS = 'http://www.portalfiscal.inf.br/nfe';

    /** Confere dados do XML efetivamente assinado ANTES de chamar a SEFAZ. */
    public function verifySigned(string $xml, FiscalDocumentJob $job): string
    {
        $dom = $this->load($xml);
        $root = $dom->documentElement;
        if (!$root || $root->localName !== 'NFe' || $root->namespaceURI !== self::NS) {
            throw new RuntimeException('ACBr não retornou uma NFC-e XML modelo 65 válida.');
        }

        $info = $root->getElementsByTagNameNS(self::NS, 'infNFe')->item(0);
        $signature = $root->getElementsByTagNameNS('http://www.w3.org/2000/09/xmldsig#', 'Signature')->item(0);
        $key = $info instanceof DOMElement ? substr($info->getAttribute('Id'), 3) : '';
        if (!$signature || preg_match('/^\d{44}$/', $key) !== 1) {
            throw new RuntimeException('XML sem assinatura digital ou chave de acesso válida.');
        }

        $this->assertValue($info, 'mod', '65');
        $this->assertValue($info, 'tpAmb', $job->environment === 'homologation' ? '2' : '1');
        $this->assertValue($info, 'serie', (string) (int) $job->series);
        $this->assertValue($info, 'nNF', (string) (int) $job->document_number);

        return $key;
    }

    /** Constrói nfeProc somente a partir do protocolo real da SEFAZ. */
    public function buildAuthorized(string $signedXml, array $response): string
    {
        if (!(new NFCeSefazResponseParser())->authorized($response)) {
            throw new RuntimeException('Não existe autorização individual válida para montar nfeProc.');
        }

        foreach (['environment', 'application', 'received_at', 'digest'] as $required) {
            if (trim((string) ($response[$required] ?? '')) === '') {
                throw new RuntimeException('Protocolo SEFAZ incompleto: '.$required);
            }
        }

        $signed = $this->load($signedXml);
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = false;
        $document->preserveWhiteSpace = true;
        $root = $document->createElementNS(self::NS, 'nfeProc');
        $root->setAttribute('versao', '4.00');
        $document->appendChild($root);
        $root->appendChild($document->importNode($signed->documentElement, true));

        $protocol = $document->createElementNS(self::NS, 'protNFe');
        $protocol->setAttribute('versao', '4.00');
        $infProt = $document->createElementNS(self::NS, 'infProt');
        foreach ([
            'tpAmb' => 'environment',
            'verAplic' => 'application',
            'chNFe' => 'key',
            'dhRecbto' => 'received_at',
            'nProt' => 'protocol',
            'digVal' => 'digest',
            'cStat' => 'cstat',
            'xMotivo' => 'reason',
        ] as $tag => $field) {
            $infProt->appendChild($document->createElementNS(self::NS, $tag))
                ->appendChild($document->createTextNode((string) $response[$field]));
        }
        $protocol->appendChild($infProt);
        $root->appendChild($protocol);

        return $document->saveXML() ?: throw new RuntimeException('Falha ao montar o XML autorizado.');
    }

    private function assertValue(DOMElement $info, string $tag, string $expected): void
    {
        $node = $info->getElementsByTagNameNS(self::NS, $tag)->item(0);
        $actual = trim((string) ($node?->textContent ?? ''));
        if (in_array($tag, ['serie', 'nNF'], true)) {
            $actual = ctype_digit($actual) ? (string) (int) $actual : $actual;
        }
        if ($actual !== $expected) {
            throw new RuntimeException('XML da NFC-e não confere com o documento reservado: '.$tag);
        }
    }

    private function load(string $xml): DOMDocument
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$document->loadXML($xml, LIBXML_NONET)) {
                throw new RuntimeException('XML fiscal retornado pela ACBr não pode ser interpretado.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $document;
    }
}
