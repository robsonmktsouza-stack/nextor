<?php

namespace App\Fiscal\Tax\Contracts;

use App\Fiscal\Models\FiscalCompany;
use App\Fiscal\Tax\DTO\NfceTaxDocumentInput;
use App\Fiscal\Tax\DTO\TaxResult;
use DateTimeInterface;

interface TaxEngineInterface
{
    public function resolve(
        FiscalCompany $company,
        NfceTaxDocumentInput $input,
        DateTimeInterface $issueAt,
    ): TaxResult;
}
