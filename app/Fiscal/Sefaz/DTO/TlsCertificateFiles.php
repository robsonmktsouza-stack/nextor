<?php

namespace App\Fiscal\Sefaz\DTO;

final readonly class TlsCertificateFiles
{
    public function __construct(
        public string $certificatePath,
        public string $privateKeyPath,
    ) {
    }
}
