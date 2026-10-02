<?php

namespace App\Fiscal\Sefaz\DTO;

use App\Fiscal\Enums\FiscalEnvironment;

final readonly class SefazStatusResult
{
    public function __construct(
        public bool $transportSuccess,
        public int $httpStatus,
        public string $fiscalStatus,
        public string $cStat,
        public string $xMotivo,
        public int $responseTimeMs,
        public string $endpointKey,
        public string $endpointUrl,
        public FiscalEnvironment $environment,
        public string $uf,
        public string $authorizer,
        public string $attemptUuid,
        public StatusServiceResponse $response,
    ) {
    }
}
