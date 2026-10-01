<?php

namespace App\Fiscal\Nfce;

use App\Fiscal\DTO\AccessKeyData;
use App\Fiscal\DTO\NfceSnapshot;
use App\Fiscal\Enums\FiscalDocumentState;
use App\Fiscal\Enums\FiscalEnvironment;
use App\Fiscal\Exceptions\InvalidFiscalDataException;
use App\Fiscal\Models\FiscalCompany;
use App\Fiscal\Models\FiscalDocument;
use App\Fiscal\Schema\SchemaCatalog;
use App\Fiscal\Support\UfCode;
use App\Models\Sale;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

final class FiscalDocumentCreator
{
    public function __construct(
        private readonly FiscalSequenceService $sequences,
        private readonly AccessKeyGenerator $keys,
    ) {
    }

    public function create(
        FiscalCompany $company,
        array $resolvedSnapshot,
        ?Sale $sale = null,
        int $series = 1,
        int $emissionType = 1,
        ?DateTimeInterface $issueAt = null,
    ): FiscalDocument {
        $environment = $company->environment instanceof FiscalEnvironment
            ? $company->environment
            : FiscalEnvironment::from((string) $company->environment);

        if ($environment === FiscalEnvironment::PRODUCTION && !$company->production_enabled) {
            throw new InvalidFiscalDataException('Produção fiscal está bloqueada para esta empresa.');
        }

        if ($emissionType !== 1) {
            throw new InvalidFiscalDataException(
                'Esta fase do Nextor Fiscal aceita somente emissão normal (tpEmis=1).'
            );
        }

        $cUf = UfCode::codeForUf((string) $company->uf);
        if ($cUf === null) {
            throw new InvalidFiscalDataException('UF da empresa fiscal é inválida.');
        }

        $issueAt ??= now()->toImmutable();
        $immutableIssueAt = DateTimeImmutable::createFromInterface($issueAt);

        return DB::transaction(function () use (
            $company,
            $resolvedSnapshot,
            $sale,
            $series,
            $emissionType,
            $environment,
            $cUf,
            $immutableIssueAt,
        ) {
            $number = $this->sequences->reserveNext($company, $series, $environment);
            $numericCode = $this->keys->generateNumericCode();

            $accessKey = $this->keys->generate(new AccessKeyData(
                cUf: $cUf,
                issueDate: $immutableIssueAt,
                emitterDocument: (string) $company->cnpj,
                model: '65',
                series: $series,
                number: $number,
                emissionType: $emissionType,
                numericCode: $numericCode,
            ));

            $snapshot = NfceSnapshot::fromResolvedData(
                $company,
                $resolvedSnapshot,
                $immutableIssueAt,
            );

            $snapshotJson = $snapshot->toJson();

            return FiscalDocument::query()->create([
                'fiscal_company_id' => $company->id,
                'sale_id' => $sale?->getKey(),
                'model' => '65',
                'series' => $series,
                'number' => $number,
                'access_key' => $accessKey,
                'environment' => $environment,
                'emission_type' => $emissionType,
                'numeric_code' => $numericCode,
                'state' => FiscalDocumentState::DRAFT,
                'layout_version' => SchemaCatalog::NFE_LAYOUT_VERSION,
                'issue_at' => $immutableIssueAt,
                'snapshot_payload' => $snapshotJson,
                'snapshot_sha256' => hash('sha256', $snapshotJson),
            ]);
        }, 5);
    }
}
