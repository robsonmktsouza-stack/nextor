<?php

namespace App\Fiscal\Signature;

use DOMDocument;
use DOMElement;
use DOMXPath;

final class XmlSigner
{
    private const NFE_NS = 'http://www.portalfiscal.inf.br/nfe';

    public function sign(
        string $xml,
        string $accessKey,
        A1SigningMaterial $material,
    ): XmlSignatureResult {
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;

        if (!@$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
            throw new XmlSignatureException('XML gerado não pôde ser carregado para assinatura.');
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('nfe', self::NFE_NS);
        $xpath->registerNamespace('ds', XmlSignatureAlgorithms::XMLDSIG_NS);

        $infNodes = $xpath->query('/nfe:NFe/nfe:infNFe');
        if ($infNodes === false || $infNodes->length !== 1 || !$infNodes->item(0) instanceof DOMElement) {
            throw new XmlSignatureException('XML deve possuir exatamente um infNFe para assinatura.');
        }

        if (($xpath->query('/nfe:NFe/ds:Signature')?->length ?? 0) !== 0) {
            throw new XmlSignatureException('XML já possui assinatura digital.');
        }

        /** @var DOMElement $infNFe */
        $infNFe = $infNodes->item(0);
        $expectedId = 'NFe'.$accessKey;

        if ($infNFe->getAttribute('Id') !== $expectedId) {
            throw new XmlSignatureException('Id de infNFe não corresponde à chave do documento.');
        }

        $canonicalInfNFe = $infNFe->C14N(false, false);
        if (!is_string($canonicalInfNFe)) {
            throw new XmlSignatureException('Falha ao canonicalizar infNFe.');
        }

        $digestValue = base64_encode(hash('sha1', $canonicalInfNFe, true));
        $referenceUri = '#'.$expectedId;

        $signature = $dom->createElementNS(
            XmlSignatureAlgorithms::XMLDSIG_NS,
            'ds:Signature',
        );
        $signedInfo = $dom->createElementNS(
            XmlSignatureAlgorithms::XMLDSIG_NS,
            'ds:SignedInfo',
        );
        $signature->appendChild($signedInfo);

        $canonicalization = $dom->createElementNS(
            XmlSignatureAlgorithms::XMLDSIG_NS,
            'ds:CanonicalizationMethod',
        );
        $canonicalization->setAttribute('Algorithm', XmlSignatureAlgorithms::C14N_10);
        $signedInfo->appendChild($canonicalization);

        $signatureMethod = $dom->createElementNS(
            XmlSignatureAlgorithms::XMLDSIG_NS,
            'ds:SignatureMethod',
        );
        $signatureMethod->setAttribute('Algorithm', XmlSignatureAlgorithms::RSA_SHA1);
        $signedInfo->appendChild($signatureMethod);

        $reference = $dom->createElementNS(
            XmlSignatureAlgorithms::XMLDSIG_NS,
            'ds:Reference',
        );
        $reference->setAttribute('URI', $referenceUri);
        $signedInfo->appendChild($reference);

        $transforms = $dom->createElementNS(
            XmlSignatureAlgorithms::XMLDSIG_NS,
            'ds:Transforms',
        );
        $reference->appendChild($transforms);

        foreach ([
            XmlSignatureAlgorithms::ENVELOPED_SIGNATURE,
            XmlSignatureAlgorithms::C14N_10,
        ] as $algorithm) {
            $transform = $dom->createElementNS(
                XmlSignatureAlgorithms::XMLDSIG_NS,
                'ds:Transform',
            );
            $transform->setAttribute('Algorithm', $algorithm);
            $transforms->appendChild($transform);
        }

        $digestMethod = $dom->createElementNS(
            XmlSignatureAlgorithms::XMLDSIG_NS,
            'ds:DigestMethod',
        );
        $digestMethod->setAttribute('Algorithm', XmlSignatureAlgorithms::SHA1);
        $reference->appendChild($digestMethod);

        $digestNode = $dom->createElementNS(
            XmlSignatureAlgorithms::XMLDSIG_NS,
            'ds:DigestValue',
            $digestValue,
        );
        $reference->appendChild($digestNode);

        $dom->documentElement?->appendChild($signature);

        $canonicalSignedInfo = $signedInfo->C14N(false, false);
        if (!is_string($canonicalSignedInfo)) {
            throw new XmlSignatureException('Falha ao canonicalizar SignedInfo.');
        }

        $signed = openssl_sign(
            $canonicalSignedInfo,
            $rawSignature,
            $material->privateKey,
            OPENSSL_ALGO_SHA1,
        );

        if (!$signed) {
            throw new XmlSignatureException('OpenSSL não conseguiu assinar o SignedInfo.');
        }

        $signatureValue = base64_encode($rawSignature);

        $signatureValueNode = $dom->createElementNS(
            XmlSignatureAlgorithms::XMLDSIG_NS,
            'ds:SignatureValue',
            $signatureValue,
        );
        $signature->appendChild($signatureValueNode);

        $keyInfo = $dom->createElementNS(
            XmlSignatureAlgorithms::XMLDSIG_NS,
            'ds:KeyInfo',
        );
        $x509Data = $dom->createElementNS(
            XmlSignatureAlgorithms::XMLDSIG_NS,
            'ds:X509Data',
        );
        $x509Certificate = $dom->createElementNS(
            XmlSignatureAlgorithms::XMLDSIG_NS,
            'ds:X509Certificate',
            $material->certificateBase64,
        );

        $x509Data->appendChild($x509Certificate);
        $keyInfo->appendChild($x509Data);
        $signature->appendChild($keyInfo);

        $signedXml = $dom->saveXML();
        if (!is_string($signedXml) || $signedXml === '') {
            throw new XmlSignatureException('Falha ao serializar XML assinado.');
        }

        return new XmlSignatureResult(
            xml: $signedXml,
            referenceUri: $referenceUri,
            digestValue: $digestValue,
            signatureValue: $signatureValue,
            certificateBase64: $material->certificateBase64,
        );
    }
}
