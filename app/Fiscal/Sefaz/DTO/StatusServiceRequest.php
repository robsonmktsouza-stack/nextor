<?php

namespace App\Fiscal\Sefaz\DTO;

use App\Fiscal\Enums\FiscalEnvironment;

final readonly class StatusServiceRequest
{
    public function __construct(
        public FiscalEnvironment $environment,
        public string $stateCode,
        public string $version = '4.00',
    ) {
    }
}
