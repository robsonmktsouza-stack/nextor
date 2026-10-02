<?php

namespace App\Fiscal\Signature;

use App\Fiscal\DTO\CertificateInfo;
use OpenSSLAsymmetricKey;
use OpenSSLCertificate;

final readonly class A1SigningMaterial
{
    public function __construct(
        public CertificateInfo $info,
        public OpenSSLCertificate|string $certificate,
        public OpenSSLAsymmetricKey|string $privateKey,
        public string $certificateBase64,
    ) {
    }
}
