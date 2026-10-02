<?php

namespace App\Fiscal\Sefaz\Services;

use App\Fiscal\Enums\FiscalEnvironment;
use App\Fiscal\Models\FiscalCompany;
use App\Fiscal\Models\FiscalTransmission;
use App\Fiscal\Sefaz\Contracts\SefazTransportInterface;
use App\Fiscal\Sefaz\DTO\SefazStatusResult;
use App\Fiscal\Sefaz\DTO\StatusServiceRequest;
use App\Fiscal\Sefaz\Endpoints\SefazEndpointRegistry;
use App\Fiscal\Sefaz\Enums\SefazServiceType;
use App\Fiscal\Sefaz\Enums\SefazTransportStatus;
use App\Fiscal\Sefaz\Exceptions\SefazEndpointException;
use App\Fiscal\Sefaz\Exceptions\SefazProductionDisabledException;
use App\Fiscal\Sefaz\Exceptions\SefazResponseException;
use App\Fiscal\Sefaz\Exceptions\SefazSoapException;
use App\Fiscal\Sefaz\Exceptions\SefazTlsException;
use App\Fiscal\Sefaz\Exceptions\SefazTransportException;
use App\Fiscal\Sefaz\Support\SefazFiscalStatusClassifier;
use App\Fiscal\Sefaz\Support\SefazTechnicalLogger;
use App\Fiscal\Soap\Soap12EnvelopeBuilder;
use App\Fiscal\Soap\Soap12ResponseParser;
use App\Fiscal\Support\UfCode;
use Illuminate\Support\Str;
use Throwable;

final class StatusServiceClient
{
    public function __construct(
        private readonly SefazEndpointRegistry $endpoints,
        private readonly StatusServiceRequestXmlBuilder $requestBuilder,
        private readonly Soap12EnvelopeBuilder $soapBuilder,
        private readonly SefazTransportInterface $transport,
        private readonly Soap12ResponseParser $soapParser,
        private readonly StatusServiceResponseParser $responseParser,
        private readonly SefazTechnicalLogger $logger,
    ) {
    }

    public function query(FiscalCompany $company): SefazStatusResult
    {
        if ($company->environment !== FiscalEnvironment::HOMOLOGATION) {
            throw new SefazProductionDisabledException(
                'Comunicação SEFAZ em produção ainda não está habilitada nesta versão do Nextor Fiscal.'
            );
        }

        if (!$company->is_active) {
            throw new SefazEndpointException('Empresa fiscal está inativa.');
        }

        $uf = strtoupper(trim((string) $company->uf));
        $stateCode = UfCode::codeForUf($uf);

        if ($stateCode === null) {
            throw new SefazEndpointException("UF fiscal inválida ou não reconhecida: {$uf}.");
        }

        $endpoint = $this->endpoints->resolve(
            uf: $uf,
            environment: FiscalEnvironment::HOMOLOGATION,
            service: SefazServiceType::STATUS_SERVICE,
        );

        $requestXml = $this->requestBuilder->build(new StatusServiceRequest(
            environment: FiscalEnvironment::HOMOLOGATION,
            stateCode: $stateCode,
            version: $endpoint->version,
        ));

        $soapRequest = $this->soapBuilder->build($endpoint, $requestXml);
        $attemptUuid = (string) Str::uuid();

        $transmission = FiscalTransmission::query()->create([
            'fiscal_company_id' => $company->id,
            'fiscal_document_id' => null,
            'service' => SefazServiceType::STATUS_SERVICE->value,
            'environment' => FiscalEnvironment::HOMOLOGATION,
            'uf' => $uf,
            'authorizer' => $endpoint->authorizer->code,
            'endpoint_key' => $endpoint->key,
            'attempt_uuid' => $attemptUuid,
            'started_at' => now(),
            'transport_status' => SefazTransportStatus::STARTED->value,
            'request_sha256' => hash('sha256', $soapRequest),
            'request_payload' => $soapRequest,
        ]);

        try {
            $http = $this->transport->post($company, $endpoint, $soapRequest);
        } catch (SefazTlsException $e) {
            $this->finishWithError($transmission, SefazTransportStatus::TLS_ERROR, $e);
            throw $e;
        } catch (SefazTransportException $e) {
            $this->finishWithError($transmission, SefazTransportStatus::TRANSPORT_ERROR, $e);
            throw $e;
        } catch (Throwable $e) {
            $wrapped = new SefazTransportException('Falha inesperada no transporte SEFAZ.', previous: $e);
            $this->finishWithError($transmission, SefazTransportStatus::TRANSPORT_ERROR, $wrapped);
            throw $wrapped;
        }

        $transmission->forceFill([
            'http_status' => $http->status,
            'duration_ms' => $http->durationMs,
            'response_sha256' => hash('sha256', $http->body),
            'response_payload' => $http->body,
        ])->save();

        if ($http->status < 200 || $http->status >= 300) {
            $exception = new SefazTransportException(
                "Autorizador respondeu HTTP {$http->status}; retorno fiscal não foi considerado sucesso."
            );
            $this->finishWithError(
                $transmission,
                SefazTransportStatus::HTTP_ERROR,
                $exception,
                $http->durationMs,
            );
            throw $exception;
        }

        try {
            $fiscalXml = $this->soapParser->extractResult($endpoint, $http->body);
        } catch (SefazSoapException $e) {
            $this->finishWithError(
                $transmission,
                SefazTransportStatus::SOAP_ERROR,
                $e,
                $http->durationMs,
            );
            throw $e;
        }

        try {
            $response = $this->responseParser->parse($fiscalXml);

            if ($response->environment !== FiscalEnvironment::HOMOLOGATION) {
                throw new SefazResponseException('SEFAZ respondeu ambiente diferente de homologação.');
            }

            if ($response->stateCode !== $stateCode) {
                throw new SefazResponseException(
                    "SEFAZ respondeu cUF {$response->stateCode}, mas a consulta foi para {$stateCode}."
                );
            }
        } catch (SefazResponseException $e) {
            $this->finishWithError(
                $transmission,
                SefazTransportStatus::RESPONSE_ERROR,
                $e,
                $http->durationMs,
            );
            throw $e;
        }

        $fiscalStatus = SefazFiscalStatusClassifier::classify($response->statusCode);

        $transmission->forceFill([
            'finished_at' => now(),
            'duration_ms' => $http->durationMs,
            'transport_status' => SefazTransportStatus::SUCCESS->value,
            'fiscal_status' => $fiscalStatus,
            'c_stat' => $response->statusCode,
            'x_motivo' => mb_substr($response->reason, 0, 255),
            'error_class' => null,
            'error_message' => null,
        ])->save();

        $this->logger->record(
            companyId: (int) $company->id,
            uf: $uf,
            environment: FiscalEnvironment::HOMOLOGATION,
            service: SefazServiceType::STATUS_SERVICE->value,
            authorizer: $endpoint->authorizer->code,
            endpointKey: $endpoint->key,
            httpStatus: $http->status,
            cStat: $response->statusCode,
            xMotivo: $response->reason,
            durationMs: $http->durationMs,
            attemptUuid: $attemptUuid,
        );

        return new SefazStatusResult(
            transportSuccess: true,
            httpStatus: $http->status,
            fiscalStatus: $fiscalStatus,
            cStat: $response->statusCode,
            xMotivo: $response->reason,
            responseTimeMs: $http->durationMs,
            endpointKey: $endpoint->key,
            endpointUrl: $endpoint->url,
            environment: FiscalEnvironment::HOMOLOGATION,
            uf: $uf,
            authorizer: $endpoint->authorizer->code,
            attemptUuid: $attemptUuid,
            response: $response,
        );
    }

    private function finishWithError(
        FiscalTransmission $transmission,
        SefazTransportStatus $status,
        Throwable $error,
        ?int $durationMs = null,
    ): void {
        $transmission->forceFill([
            'finished_at' => now(),
            'duration_ms' => $durationMs ?? $transmission->duration_ms,
            'transport_status' => $status->value,
            'error_class' => $error::class,
            'error_message' => mb_substr($error->getMessage(), 0, 1000),
        ])->save();

        $this->logger->record(
            companyId: (int) $transmission->fiscal_company_id,
            uf: (string) $transmission->uf,
            environment: FiscalEnvironment::HOMOLOGATION,
            service: (string) $transmission->service,
            authorizer: (string) $transmission->authorizer,
            endpointKey: (string) $transmission->endpoint_key,
            httpStatus: $transmission->http_status,
            cStat: $transmission->c_stat,
            xMotivo: $transmission->x_motivo,
            durationMs: $transmission->duration_ms,
            attemptUuid: (string) $transmission->attempt_uuid,
        );
    }
}
