<?php

namespace App\Fiscal\Signature;

final readonly class XmlSignatureVerificationResult
{
    /** @param list<string> $errors */
    public function __construct(
        public bool $valid,
        public array $errors,
    ) {
    }
}
