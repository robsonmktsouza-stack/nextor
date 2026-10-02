<?php

namespace App\Fiscal\Signature;

final readonly class XmlSignatureResult
{
    public function __construct(
        public string $xml,
        public string $referenceUri,
        public string $digestValue,
        public string $signatureValue,
        public string $certificateBase64,
    ) {
    }
}
