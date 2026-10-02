<?php

namespace App\Fiscal\Contracts;

use App\Fiscal\Models\FiscalCompany;
use App\Fiscal\Models\FiscalDocument;
use App\Fiscal\Sefaz\DTO\SefazStatusResult;
use App\Fiscal\Tax\DTO\NfceTaxDocumentInput;
use App\Fiscal\Validation\FiscalValidationResult;
use App\Models\Sale;
use DateTimeInterface;

interface FiscalEngineInterface
{
    public function createDocument(
        FiscalCompany $company,
        NfceTaxDocumentInput $input,
        ?Sale $sale = null,
        int $series = 1,
        ?DateTimeInterface $issueAt = null,
    ): FiscalDocument;

    public function generate(FiscalDocument $document): FiscalDocument;

    public function sign(FiscalDocument $document): FiscalDocument;

    public function validate(FiscalDocument $document): FiscalValidationResult;

    public function statusService(FiscalCompany $company): SefazStatusResult;

    public function getGeneratedXml(FiscalDocument $document): ?string;

    public function getSignedXml(FiscalDocument $document): ?string;
}
