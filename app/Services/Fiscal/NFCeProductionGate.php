<?php

namespace App\Services\Fiscal;

use App\Models\AppSetting;
use RuntimeException;

/**
 * Fiscal lifecycle operations are disabled in production by default.
 * Explicit approval is set only AFTER ACBr/SEFAZ homologation evidence
 * has been reviewed; ordinary NFC-e issuance is not changed.
 */
final class NFCeProductionGate
{
    public function assertAllowed(string $environment): void
    {
        if ($environment === 'production'
            && !(bool)AppSetting::value('nfce','advanced_operations_production_approved',false)) {
            throw new RuntimeException(
                'Cancelamento, inutilização e contingência em produção ainda não foram homologados nesta instalação.'
            );
        }
    }
}
