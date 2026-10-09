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
        $this->assertValue($info, 'tpEmis', $job->emission_mode==='offline'?'9':'1');
        if ($job->emission_mode==='offline') {
            $reason=trim((string)$info->getElementsByTagNameNS(self::NS,'xJust')->item(0)?->textContent);
            $date=trim((string)$info->getElementsByTagNameNS(self::NS,'dhCont')->item(0)?->textContent);
            if (mb_strlen($reason)<15 || !$date) {
                throw new RuntimeException('XML assinado sem dados obrigatórios de contingência offline.');
            }
        }

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
        $info = $signed->documentElement?->getElementsByTagNameNS(self::NS, 'infNFe')->item(0);
        $signedKey = $info instanceof DOMElement ? substr($info->getAttribute('Id'), 3) : '';
        if (preg_match('/^\\d{44}$/', $signedKey) !== 1
            || !hash_equals($signedKey, (string) $response['key'])) {
            throw new RuntimeException('Protocolo SEFAZ não corresponde à chave da NFC-e assinada.');
        }
        // A assinatura XML usa C14N inclusiva. DOM::importNode() pode mover
        // declarações de namespaces para um ancestral diferente e invalidar
        // DigestValue/SignatureValue, apesar de a SEFAZ ter autorizado.
        // Preservar o NFe assinado byte a byte ao montar nfeProc.
        $receivedAt = $this->receivedAtIso((string) $response['received_at']);
        $fields = [
            'tpAmb' => (string) $response['environment'],
            'verAplic' => (string) $response['application'],
            'chNFe' => (string) $response['key'],
            'dhRecbto' => $receivedAt,
            'nProt' => (string) $response['protocol'],
            'digVal' => (string) $response['digest'],
            'cStat' => (string) $response['cstat'],
            'xMotivo' => (string) $response['reason'],
        ];
        $protocolXml = '<protNFe versao="4.00"><infProt>';
        foreach ($fields as $tag => $value) {
            $protocolXml .= '<'.$tag.'>'.htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</'.$tag.'>';
        }
        $protocolXml .= '</infProt></protNFe>';

        $nfeXml = trim((string) preg_replace('/^(?:\xEF\xBB\xBF)?\s*<\?xml\s+[^?]*\?>\s*/i', '', $signedXml));
        if (str_starts_with($nfeXml, '<?xml')) {
            // Remover apenas declaração XML externa; nunca modificar elementos
            // ou namespaces do NFe assinado.
            $nfeXml = trim((string) preg_replace('/^<\?xml\s+[^?]*\?>\s*/i', '', $nfeXml));
        }
        $authorizedXml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<nfeProc xmlns="'.self::NS.'" versao="4.00">'
            .$nfeXml
            .$protocolXml
            .'</nfeProc>';

        $authorized = $this->load($authorizedXml);
        if ($authorized->documentElement?->localName !== 'nfeProc'
            || $authorized->documentElement?->namespaceURI !== self::NS) {
            throw new RuntimeException('Falha ao montar nfeProc autorizado.');
        }

        // Impedir o salvamento de XML que altere o escopo canônico utilizado na
        // assinatura. Mesmo assinaturas válidas podem ser danificadas por importNode.
        foreach ([
            [self::NS, 'infNFe'],
            ['http://www.w3.org/2000/09/xmldsig#', 'SignedInfo'],
        ] as [$namespace, $tag]) {
            $before = $signed->getElementsByTagNameNS($namespace, $tag)->item(0);
            $after = $authorized->getElementsByTagNameNS($namespace, $tag)->item(0);
            if (!$before instanceof DOMElement || !$after instanceof DOMElement
                || !hash_equals((string) $before->C14N(), (string) $after->C14N())) {
                throw new RuntimeException('Empacotamento da NFC-e alterou a canonicalização da assinatura digital.');
            }
        }

        return $authorizedXml;
    }

    private function receivedAtIso(string $input): string
    {
        $value = trim($input);
        if (preg_match('/^\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}:\d{2}$/', $value)) {
            // Este emissor só habilita BA; o horário de resposta da ACBr é
            // fornecido na hora local da SEFAZ, sem deslocamento explícito.
            $date = \DateTimeImmutable::createFromFormat('!d/m/Y H:i:s', $value, new \DateTimeZone('America/Bahia'));
            if (!$date || $date->format('d/m/Y H:i:s') !== $value) {
                throw new RuntimeException('Data de recebimento SEFAZ inválida.');
            }

            return $date->format('Y-m-d\TH:i:sP');
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/', $value)) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $value);
            if ($date instanceof \DateTimeImmutable) {
                return $date->format('Y-m-d\TH:i:sP');
            }
        }

        throw new RuntimeException('Formato de data de recebimento da SEFAZ não reconhecido.');
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
