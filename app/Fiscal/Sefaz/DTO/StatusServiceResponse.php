<?php

namespace App\Fiscal\Sefaz\DTO;

use App\Fiscal\Enums\FiscalEnvironment;

final readonly class StatusServiceResponse
{
    public function __construct(
        public FiscalEnvironment $environment,
        public string $applicationVersion,
        public string $stateCode,
        public string $statusCode,
        public string $reason,
        public ?string $receivedAt,
        public ?string $averageTime,
        public ?string $returnAt,
        public ?string $observation,
        public string $rawXmlHash,
    ) {
    }
}
