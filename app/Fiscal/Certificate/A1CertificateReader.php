<?php

namespace App\Fiscal\Certificate;

use App\Fiscal\DTO\CertificateInfo;
use App\Fiscal\Exceptions\CertificateException;
use DateTimeImmutable;
use OpenSSLAsymmetricKey;
use OpenSSLCertificate;

final class A1CertificateReader
{
    public function __construct(
        private readonly IcpBrasilSubjectDocumentExtractor $documentExtractor = new IcpBrasilSubjectDocumentExtractor(),
    ) {
    }

    /**
     * @return array{info: CertificateInfo, certificate: OpenSSLCertificate|string, private_key: OpenSSLAsymmetricKey|string}
     */
    public function read(string $pfxBytes, string $password): array
    {
        if (!extension_loaded('openssl')) {
            throw new CertificateException('Extensão OpenSSL não está disponível no PHP.');
        }

        $store = [];

        if (!openssl_pkcs12_read($pfxBytes, $store, $password)) {
            throw new CertificateException('Não foi possível abrir o certificado A1. Verifique o arquivo e a senha.');
        }

        if (!isset($store['cert'], $store['pkey'])) {
            throw new CertificateException('O PFX/P12 não contém certificado e chave privada utilizáveis.');
        }

        $parsed = openssl_x509_parse($store['cert']);
        if (!is_array($parsed)) {
            throw new CertificateException('Não foi possível interpretar o certificado X.509.');
        }

        $info = new CertificateInfo(
            subject: $this->flattenDn($parsed['subject'] ?? null),
            issuer: $this->flattenDn($parsed['issuer'] ?? null),
            serialNumber: $parsed['serialNumberHex'] ?? ($parsed['serialNumber'] ?? null),
            fingerprintSha256: openssl_x509_fingerprint($store['cert'], 'sha256') ?: null,
            subjectDocument: $this->documentExtractor->extract($store['cert'], $parsed),
            validFrom: isset($parsed['validFrom_time_t'])
                ? (new DateTimeImmutable())->setTimestamp((int) $parsed['validFrom_time_t'])
                : null,
            validTo: isset($parsed['validTo_time_t'])
                ? (new DateTimeImmutable())->setTimestamp((int) $parsed['validTo_time_t'])
                : null,
        );

        return [
            'info' => $info,
            'certificate' => $store['cert'],
            'private_key' => $store['pkey'],
        ];
    }

    private function flattenDn(mixed $dn): ?string
    {
        if (!is_array($dn)) {
            return null;
        }

        $parts = [];
        foreach ($dn as $key => $value) {
            if (is_scalar($value)) {
                $parts[] = $key.'='.(string) $value;
            }
        }

        return $parts ? implode(', ', $parts) : null;
    }
}
