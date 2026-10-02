<?php

namespace App\Fiscal\Signature;

use DOMDocument;
use DOMElement;
use DOMXPath;

final class XmlSignatureVerifier
{
    private const NFE_NS = 'http://www.portalfiscal.inf.br/nfe';

    public function verify(string $xml, string $accessKey): XmlSignatureVerificationResult
    {
        $errors = [];
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;

        if (!@$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
            return new XmlSignatureVerificationResult(false, ['XML assinado não é XML bem-formado.']);
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('nfe', self::NFE_NS);
        $xpath->registerNamespace('ds', XmlSignatureAlgorithms::XMLDSIG_NS);

        $infNodes = $xpath->query('/nfe:NFe/nfe:infNFe');
        $signatureNodes = $xpath->query('/nfe:NFe/ds:Signature');

        if ($infNodes === false || $infNodes->length !== 1 || !$infNodes->item(0) instanceof DOMElement) {
            $errors[] = 'Assinatura não possui exatamente um infNFe alvo.';
        }

        if ($signatureNodes === false || $signatureNodes->length !== 1 || !$signatureNodes->item(0) instanceof DOMElement) {
            $errors[] = 'Documento não possui exatamente uma assinatura XMLDSig.';
        }

        if ($errors !== []) {
            return new XmlSignatureVerificationResult(false, $errors);
        }

        /** @var DOMElement $infNFe */
        $infNFe = $infNodes->item(0);
        /** @var DOMElement $signature */
        $signature = $signatureNodes->item(0);
        $expectedId = 'NFe'.$accessKey;

        if ($infNFe->getAttribute('Id') !== $expectedId) {
            $errors[] = 'Id do infNFe não corresponde à chave de acesso esperada.';
        }

        $reference = $xpath->query('ds:SignedInfo/ds:Reference', $signature)?->item(0);
        if (!$reference instanceof DOMElement || $reference->getAttribute('URI') !== '#'.$expectedId) {
            $errors[] = 'Reference URI não aponta para o infNFe esperado.';
        }

        $canonicalization = $xpath->evaluate(
            'string(ds:SignedInfo/ds:CanonicalizationMethod/@Algorithm)',
            $signature,
        );
        $signatureMethod = $xpath->evaluate(
            'string(ds:SignedInfo/ds:SignatureMethod/@Algorithm)',
            $signature,
        );
        $digestMethod = $xpath->evaluate(
            'string(ds:SignedInfo/ds:Reference/ds:DigestMethod/@Algorithm)',
            $signature,
        );

        if ($canonicalization !== XmlSignatureAlgorithms::C14N_10) {
            $errors[] = 'CanonicalizationMethod diferente do perfil NF-e.';
        }

        if ($signatureMethod !== XmlSignatureAlgorithms::RSA_SHA1) {
            $errors[] = 'SignatureMethod diferente do perfil NF-e.';
        }

        if ($digestMethod !== XmlSignatureAlgorithms::SHA1) {
            $errors[] = 'DigestMethod diferente do perfil NF-e.';
        }

        $transformNodes = $xpath->query(
            'ds:SignedInfo/ds:Reference/ds:Transforms/ds:Transform',
            $signature,
        );
        $transforms = [];

        if ($transformNodes !== false) {
            foreach ($transformNodes as $transform) {
                if ($transform instanceof DOMElement) {
                    $transforms[] = $transform->getAttribute('Algorithm');
                }
            }
        }

        if ($transforms !== [
            XmlSignatureAlgorithms::ENVELOPED_SIGNATURE,
            XmlSignatureAlgorithms::C14N_10,
        ]) {
            $errors[] = 'Transforms da referência não correspondem ao perfil NF-e.';
        }

        $canonicalInfNFe = $infNFe->C14N(false, false);
        $storedDigest = $xpath->evaluate(
            'string(ds:SignedInfo/ds:Reference/ds:DigestValue)',
            $signature,
        );

        if (
            !is_string($canonicalInfNFe)
            || !hash_equals(base64_encode(hash('sha1', $canonicalInfNFe, true)), $storedDigest)
        ) {
            $errors[] = 'DigestValue não confere com o conteúdo atual de infNFe.';
        }

        $certificateBase64 = preg_replace(
            '/\s+/',
            '',
            (string) $xpath->evaluate('string(ds:KeyInfo/ds:X509Data/ds:X509Certificate)', $signature),
        );

        $signatureValue = preg_replace(
            '/\s+/',
            '',
            (string) $xpath->evaluate('string(ds:SignatureValue)', $signature),
        );

        $signedInfo = $xpath->query('ds:SignedInfo', $signature)?->item(0);

        if (
            !is_string($certificateBase64)
            || $certificateBase64 === ''
            || base64_decode($certificateBase64, true) === false
        ) {
            $errors[] = 'X509Certificate ausente ou inválido.';
        }

        if (
            !is_string($signatureValue)
            || $signatureValue === ''
            || base64_decode($signatureValue, true) === false
        ) {
            $errors[] = 'SignatureValue ausente ou inválido.';
        }

        if (!$signedInfo instanceof DOMElement) {
            $errors[] = 'SignedInfo ausente.';
        }

        if ($errors !== []) {
            return new XmlSignatureVerificationResult(false, $errors);
        }

        $certificatePem = "-----BEGIN CERTIFICATE-----\n"
            .chunk_split($certificateBase64, 64, "\n")
            ."-----END CERTIFICATE-----\n";

        $publicKey = openssl_pkey_get_public($certificatePem);
        if ($publicKey === false) {
            return new XmlSignatureVerificationResult(false, ['Chave pública do X509Certificate não pôde ser lida.']);
        }

        $canonicalSignedInfo = $signedInfo->C14N(false, false);
        if (!is_string($canonicalSignedInfo)) {
            return new XmlSignatureVerificationResult(false, ['SignedInfo não pôde ser canonicalizado.']);
        }

        $verified = openssl_verify(
            $canonicalSignedInfo,
            base64_decode($signatureValue, true),
            $publicKey,
            OPENSSL_ALGO_SHA1,
        );

        if ($verified !== 1) {
            $errors[] = 'SignatureValue não confere com SignedInfo e o certificado público informado.';
        }

        return new XmlSignatureVerificationResult($errors === [], $errors);
    }
}
