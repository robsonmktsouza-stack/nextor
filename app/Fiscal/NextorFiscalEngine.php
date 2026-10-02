<?php

namespace App\Fiscal;

use App\Fiscal\Contracts\FiscalEngineInterface;
use App\Fiscal\Models\FiscalCompany;
use App\Fiscal\Models\FiscalDocument;
use App\Fiscal\Nfce\FiscalDocumentCreator;
use App\Fiscal\Sefaz\DTO\SefazStatusResult;
use App\Fiscal\Sefaz\Services\StatusServiceClient;
use App\Fiscal\Signature\FiscalDocumentSignatureService;
use App\Fiscal\Tax\Contracts\TaxEngineInterface;
use App\Fiscal\Tax\DTO\NfceTaxDocumentInput;
use App\Fiscal\Validation\FiscalDocumentValidationService;
use App\Fiscal\Validation\FiscalValidationResult;
use App\Fiscal\Xml\NfceXmlService;
use App\Models\Sale;
use DateTimeImmutable;
use DateTimeInterface;

final class NextorFiscalEngine implements FiscalEngineInterface
{
    public function __construct(
        private readonly TaxEngineInterface $taxEngine,
        private readonly FiscalDocumentCreator $documents,
        private readonly NfceXmlService $xml,
        private readonly FiscalDocumentSignatureService $signatures,
        private readonly FiscalDocumentValidationService $validation,
        private readonly StatusServiceClient $statusServiceClient,
    ) {
    }

    public function createDocument(
        FiscalCompany $company,
        NfceTaxDocumentInput $input,
        ?Sale $sale = null,
        int $series = 1,
        ?DateTimeInterface $issueAt = null,
    ): FiscalDocument {
        $issueAt ??= now()->toImmutable();
        $issueAt = DateTimeImmutable::createFromInterface($issueAt);

        $taxResult = $this->taxEngine->resolve($company, $input, $issueAt);
        $snapshot = $taxResult->toResolvedSnapshot($input);

        return $this->documents->create(
            company: $company,
            resolvedSnapshot: $snapshot,
            sale: $sale,
            series: $series,
            emissionType: 1,
            issueAt: $issueAt,
        );
    }

    public function generate(FiscalDocument $document): FiscalDocument
    {
        return $this->xml->generate($document);
    }

    public function sign(FiscalDocument $document): FiscalDocument
    {
        return $this->signatures->sign($document);
    }

    public function validate(FiscalDocument $document): FiscalValidationResult
    {
        return $this->validation->validate($document);
    }

    public function statusService(FiscalCompany $company): SefazStatusResult
    {
        return $this->statusServiceClient->query($company);
    }

    public function getGeneratedXml(FiscalDocument $document): ?string
    {
        return $document->xml_generated;
    }

    public function getSignedXml(FiscalDocument $document): ?string
    {
        return $document->xml_signed;
    }
}
