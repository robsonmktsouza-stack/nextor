<?php

namespace App\Fiscal\Sefaz\Contracts;

use App\Fiscal\Models\FiscalCompany;
use App\Fiscal\Sefaz\DTO\SefazEndpoint;
use App\Fiscal\Sefaz\DTO\SefazHttpResponse;

interface SefazTransportInterface
{
    public function post(
        FiscalCompany $company,
        SefazEndpoint $endpoint,
        string $body,
    ): SefazHttpResponse;
}
