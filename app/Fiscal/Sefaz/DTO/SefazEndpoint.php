<?php

namespace App\Fiscal\Sefaz\DTO;

use App\Fiscal\Enums\FiscalEnvironment;
use App\Fiscal\Sefaz\Enums\SefazServiceType;

final readonly class SefazEndpoint
{
    public function __construct(
        public string $key,
        public string $uf,
        public SefazAuthorizer $authorizer,
        public FiscalEnvironment $environment,
        public SefazServiceType $service,
        public string $version,
        public string $url,
        public string $wsdlNamespace,
        public string $operation,
        public string $soapAction,
    ) {
    }
}
