<?php

namespace App\Support;

use App\Services\License\CentralLicenseService;

/**
 * Guard scheduled work that creates new commercial data outside HTTP routes.
 * Existing fiscal regularization/consultation jobs and webhook delivery are
 * not commercial creation: they must retain their reconciliation paths.
 */
final class CommercialBackgroundAccess
{
    public static function allows(string $module): bool
    {
        if (!ManagedLicenseConfig::enabled()) {
            return true; // Compatibility with installations not yet enrolled.
        }

        $state = app(CentralLicenseService::class)->state();

        return ($state['status'] ?? 'unverified') === 'active'
            && in_array($module, $state['modules'] ?? [], true);
    }
}
