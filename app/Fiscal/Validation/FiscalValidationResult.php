<?php

namespace App\Fiscal\Validation;

use App\Fiscal\Schema\SchemaValidationResult;
use App\Fiscal\Signature\XmlSignatureVerificationResult;

final readonly class FiscalValidationResult
{
    public function __construct(
        public bool $valid,
        public XmlSignatureVerificationResult $signature,
        public SchemaValidationResult $schema,
    ) {
    }
}
