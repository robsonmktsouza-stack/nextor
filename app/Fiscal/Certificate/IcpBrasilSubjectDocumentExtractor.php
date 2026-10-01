<?php

namespace App\Fiscal\Certificate;

use App\Fiscal\Exceptions\CertificateException;
use OpenSSLCertificate;

final class IcpBrasilSubjectDocumentExtractor
{
    private const OID_SUBJECT_ALT_NAME = '2.5.29.17';
    private const OID_PF_DATA = '2.16.76.1.3.1';
    private const OID_PJ_CNPJ = '2.16.76.1.3.3';

    public function __construct(private readonly Asn1DerReader $der = new Asn1DerReader())
    {
    }

    public function extract(OpenSSLCertificate|string $certificate, array $parsed): ?string
    {
        $fromDer = $this->extractFromCertificate($certificate);
        if ($fromDer !== null) {
            return $fromDer;
        }

        $fromParsedSan = $this->extractFromParsedSubjectAltName(
            $parsed['extensions']['subjectAltName'] ?? null,
        );

        if ($fromParsedSan !== null) {
            return $fromParsedSan;
        }

        return $this->extractFromSubject($parsed['subject'] ?? []);
    }

    public function extractFromSubjectAltNameDer(string $subjectAltNameDer): ?string
    {
        try {
            $offset = 0;
            $root = $this->der->read($subjectAltNameDer, $offset);
            $generalNames = ($root['class'] === 0 && $root['tag'] === 16)
                ? $this->der->children($root['value'])
                : $this->der->children($subjectAltNameDer);

            foreach ($generalNames as $generalName) {
                if ($generalName['class'] !== 2 || $generalName['tag'] !== 0) {
                    continue;
                }

                $children = $this->der->children($generalName['value']);

                if (
                    count($children) === 1
                    && $children[0]['class'] === 0
                    && $children[0]['tag'] === 16
                ) {
                    $children = $this->der->children($children[0]['value']);
                }

                $oid = null;
                $valueNode = null;

                foreach ($children as $child) {
                    if ($oid === null && $child['class'] === 0 && $child['tag'] === 6) {
                        $oid = $this->der->decodeOid($child['value']);
                        continue;
                    }

                    if ($oid !== null) {
                        $valueNode = $child;
                        break;
                    }
                }

                if ($oid === null || $valueNode === null) {
                    continue;
                }

                $text = $this->der->decodeText($valueNode);
                $document = $this->documentForOid($oid, $text);

                if ($document !== null) {
                    return $document;
                }
            }
        } catch (CertificateException) {
            return null;
        }

        return null;
    }

    private function extractFromCertificate(OpenSSLCertificate|string $certificate): ?string
    {
        if (!openssl_x509_export($certificate, $pem, true)) {
            return null;
        }

        $der = $this->pemToDer($pem);
        if ($der === null) {
            return null;
        }

        try {
            $offset = 0;
            $root = $this->der->read($der, $offset);

            return $this->findSubjectAltName($root);
        } catch (CertificateException) {
            return null;
        }
    }

    /**
     * @param array{class:int,constructed:bool,tag:int,value:string} $node
     */
    private function findSubjectAltName(array $node): ?string
    {
        if (!$node['constructed']) {
            return null;
        }

        $children = $this->der->children($node['value']);

        if ($node['class'] === 0 && $node['tag'] === 16 && $children !== []) {
            $first = $children[0];

            if ($first['class'] === 0 && $first['tag'] === 6) {
                $oid = $this->der->decodeOid($first['value']);

                if ($oid === self::OID_SUBJECT_ALT_NAME) {
                    foreach (array_reverse($children) as $child) {
                        if ($child['class'] === 0 && $child['tag'] === 4) {
                            return $this->extractFromSubjectAltNameDer($child['value']);
                        }
                    }
                }
            }
        }

        foreach ($children as $child) {
            $document = $this->findSubjectAltName($child);
            if ($document !== null) {
                return $document;
            }
        }

        return null;
    }

    private function extractFromParsedSubjectAltName(mixed $subjectAltName): ?string
    {
        if (!is_string($subjectAltName) || $subjectAltName === '') {
            return null;
        }

        if (preg_match(
            '/2\.16\.76\.1\.3\.3[^A-Z0-9]*([A-Z0-9]{12}[0-9]{2})/i',
            $subjectAltName,
            $matches,
        )) {
            return strtoupper($matches[1]);
        }

        if (preg_match(
            '/2\.16\.76\.1\.3\.1[^0-9]*([0-9]{19,})/',
            $subjectAltName,
            $matches,
        )) {
            return substr($matches[1], 8, 11);
        }

        return null;
    }

    private function documentForOid(string $oid, string $text): ?string
    {
        if ($oid === self::OID_PJ_CNPJ) {
            $normalized = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $text) ?? '');

            if (preg_match('/([A-Z0-9]{12}[0-9]{2})/', $normalized, $matches)) {
                return $matches[1];
            }

            return null;
        }

        if ($oid === self::OID_PF_DATA) {
            $digits = preg_replace('/\D/', '', $text) ?? '';

            if (strlen($digits) >= 19) {
                $cpf = substr($digits, 8, 11);

                return preg_match('/^[0-9]{11}$/', $cpf) ? $cpf : null;
            }
        }

        return null;
    }

    private function extractFromSubject(mixed $subject): ?string
    {
        if (!is_array($subject)) {
            return null;
        }

        foreach ($subject as $value) {
            if (!is_scalar($value)) {
                continue;
            }

            $normalized = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) $value) ?? '');

            if (preg_match('/([A-Z0-9]{12}[0-9]{2})/', $normalized, $matches)) {
                return $matches[1];
            }
        }

        foreach (['serialNumber', 'SERIALNUMBER', '2.5.4.5'] as $key) {
            if (!isset($subject[$key]) || !is_scalar($subject[$key])) {
                continue;
            }

            $digits = preg_replace('/\D/', '', (string) $subject[$key]) ?? '';
            if (preg_match('/([0-9]{11})/', $digits, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    private function pemToDer(string $pem): ?string
    {
        $base64 = preg_replace(
            '/-----BEGIN CERTIFICATE-----|-----END CERTIFICATE-----|\s+/',
            '',
            $pem,
        );

        if (!is_string($base64) || $base64 === '') {
            return null;
        }

        $der = base64_decode($base64, true);

        return $der === false ? null : $der;
    }
}
