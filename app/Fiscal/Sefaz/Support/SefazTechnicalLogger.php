<?php

namespace App\Fiscal\Sefaz\Support;

use App\Fiscal\Enums\FiscalEnvironment;
use Illuminate\Support\Facades\Log;

final class SefazTechnicalLogger
{
    public function record(
        int $companyId,
        string $uf,
        FiscalEnvironment $environment,
        string $service,
        string $authorizer,
        string $endpointKey,
        ?int $httpStatus,
        ?string $cStat,
        ?string $xMotivo,
        ?int $durationMs,
        string $attemptUuid,
    ): void {
        Log::info('NEXTOR_FISCAL_SEFAZ', [
            'company_id' => $companyId,
            'uf' => $uf,
            'environment' => $environment->name,
            'service' => $service,
            'authorizer' => $authorizer,
            'endpoint_key' => $endpointKey,
            'http_status' => $httpStatus,
            'c_stat' => $cStat,
            'x_motivo' => $xMotivo,
            'duration_ms' => $durationMs,
            'attempt_uuid' => $attemptUuid,
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
