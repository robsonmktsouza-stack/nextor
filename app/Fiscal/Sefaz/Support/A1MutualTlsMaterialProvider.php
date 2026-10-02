<?php

namespace App\Fiscal\Sefaz\Support;

use App\Fiscal\Certificate\A1CertificateReader;
use App\Fiscal\Models\FiscalCompany;
use App\Fiscal\Sefaz\DTO\TlsCertificateFiles;
use App\Fiscal\Sefaz\Exceptions\SefazTlsException;
use DateTimeImmutable;

final class A1MutualTlsMaterialProvider
{
    public function __construct(private readonly A1CertificateReader $reader)
    {
    }

    /**
     * Material sensível existe em disco apenas durante a execução do callback.
     */
    public function withPemFiles(FiscalCompany $company, callable $callback): mixed
    {
        $certificate = $company->certificate()
            ->where('is_active', true)
            ->first();

        if (!$certificate) {
            throw new SefazTlsException('Empresa fiscal não possui certificado A1 ativo.');
        }

        $now = new DateTimeImmutable();
        if ($certificate->valid_to !== null && $certificate->valid_to->toImmutable() < $now) {
            throw new SefazTlsException('Certificado A1 armazenado está expirado.');
        }

        if ($certificate->valid_from !== null && $certificate->valid_from->toImmutable() > $now) {
            throw new SefazTlsException('Certificado A1 ainda não está vigente.');
        }

        $material = $this->reader->read(
            $certificate->pfxBytes(),
            (string) $certificate->pfx_password,
        );

        if ($material['info']->isExpired($now)) {
            throw new SefazTlsException('Certificado A1 está expirado.');
        }

        if ($material['info']->validFrom !== null && $material['info']->validFrom > $now) {
            throw new SefazTlsException('Certificado A1 ainda não está vigente.');
        }

        $companyDocument = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) $company->cnpj) ?? '');
        if (
            $material['info']->subjectDocument !== null
            && strtoupper($material['info']->subjectDocument) !== $companyDocument
        ) {
            throw new SefazTlsException(
                'Documento do titular do certificado A1 não corresponde ao emitente fiscal.'
            );
        }

        $certificatePem = $this->exportCertificate($material['certificate']);
        foreach ($material['extra_certificates'] as $extra) {
            $certificatePem .= $this->exportCertificate($extra);
        }

        if (!openssl_pkey_export($material['private_key'], $privateKeyPem)) {
            throw new SefazTlsException('Não foi possível preparar a chave privada A1 para mTLS.');
        }

        $directory = storage_path('app/private/fiscal/tls');
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new SefazTlsException('Não foi possível criar diretório privado temporário para mTLS.');
        }

        @chmod($directory, 0700);

        $token = bin2hex(random_bytes(24));
        $certificatePath = $directory.DIRECTORY_SEPARATOR.$token.'.cert.pem';
        $privateKeyPath = $directory.DIRECTORY_SEPARATOR.$token.'.key.pem';

        try {
            if (file_put_contents($certificatePath, $certificatePem, LOCK_EX) === false) {
                throw new SefazTlsException('Não foi possível preparar certificado temporário para mTLS.');
            }

            if (file_put_contents($privateKeyPath, $privateKeyPem, LOCK_EX) === false) {
                throw new SefazTlsException('Não foi possível preparar chave temporária para mTLS.');
            }

            @chmod($certificatePath, 0600);
            @chmod($privateKeyPath, 0600);

            return $callback(new TlsCertificateFiles(
                certificatePath: $certificatePath,
                privateKeyPath: $privateKeyPath,
            ));
        } finally {
            if (isset($privateKeyPem) && is_string($privateKeyPem)) {
                $privateKeyPem = str_repeat("\0", strlen($privateKeyPem));
            }

            @unlink($certificatePath);
            @unlink($privateKeyPath);
        }
    }

    private function exportCertificate(mixed $certificate): string
    {
        if (!openssl_x509_export($certificate, $pem, true) || !is_string($pem)) {
            throw new SefazTlsException('Não foi possível exportar certificado X.509 para mTLS.');
        }

        return $pem;
    }
}
