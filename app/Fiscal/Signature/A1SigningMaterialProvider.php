<?php

namespace App\Fiscal\Signature;

use App\Fiscal\Certificate\A1CertificateReader;
use App\Fiscal\Models\FiscalCompany;
use DateTimeImmutable;

final class A1SigningMaterialProvider
{
    public function __construct(private readonly A1CertificateReader $reader)
    {
    }

    public function forCompany(FiscalCompany $company): A1SigningMaterial
    {
        $certificate = $company->certificate()
            ->where('is_active', true)
            ->first();

        if (!$certificate) {
            throw new XmlSignatureException('Empresa fiscal não possui certificado A1 ativo.');
        }

        $now = new DateTimeImmutable();

        if ($certificate->valid_to !== null && $certificate->valid_to->toImmutable() < $now) {
            throw new XmlSignatureException('Certificado A1 armazenado está expirado.');
        }

        if ($certificate->valid_from !== null && $certificate->valid_from->toImmutable() > $now) {
            throw new XmlSignatureException('Certificado A1 ainda não está vigente.');
        }

        $material = $this->reader->read(
            $certificate->pfxBytes(),
            (string) $certificate->pfx_password,
        );

        $info = $material['info'];

        if ($info->isExpired($now)) {
            throw new XmlSignatureException('Certificado A1 está expirado.');
        }

        if ($info->validFrom !== null && $info->validFrom > $now) {
            throw new XmlSignatureException('Certificado A1 ainda não está vigente.');
        }

        $companyDocument = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) $company->cnpj) ?? '');

        if (
            $info->subjectDocument !== null
            && strtoupper($info->subjectDocument) !== $companyDocument
        ) {
            throw new XmlSignatureException(
                'Documento do titular do certificado A1 não corresponde ao emitente fiscal.'
            );
        }

        if (!openssl_x509_export($material['certificate'], $pem, true)) {
            throw new XmlSignatureException('Não foi possível exportar o certificado X.509 para assinatura.');
        }

        $base64 = preg_replace(
            '/-----BEGIN CERTIFICATE-----|-----END CERTIFICATE-----|\s+/',
            '',
            $pem,
        );

        if (!is_string($base64) || $base64 === '' || base64_decode($base64, true) === false) {
            throw new XmlSignatureException('Certificado X.509 não pôde ser convertido para Base64.');
        }

        return new A1SigningMaterial(
            info: $info,
            certificate: $material['certificate'],
            privateKey: $material['private_key'],
            certificateBase64: $base64,
        );
    }
}
