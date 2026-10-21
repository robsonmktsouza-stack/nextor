<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Commercial licensing of existing ERP modules. This is deliberately
 * separate from each employee's permissions and from fiscal continuity.
 */
final class CommercialModuleAccess
{
    public const ALLOWED = ['pdv', 'products', 'finance', 'fiscal', 'services'];

    public static function forRequest(Request $request): ?string
    {
        // The following endpoints remain usable when the fiscal subscription
        // is absent, for consultation and legal regularization of old records.
        if (self::fiscalContinuity($request)) {
            return null;
        }

        $path = $request->path();

        return match (true) {
            $request->is('pdv', 'pdv/*', 'sales', 'sales/*', 'customers', 'customers/*') => 'pdv',
            $request->is('products', 'products/*', 'stock', 'stock/*') => 'products',
            $request->is('services', 'services/*') => 'services',
            $request->is('finance', 'finance/*') => 'finance',
            $request->is('fiscal', 'fiscal/*') => 'fiscal',
            default => null,
        };
    }

    public static function fiscalContinuity(Request $request): bool
    {
        if ($request->routeIs(...CommercialLicensePolicy::CONTINUITY_ROUTES)) {
            return true;
        }

        // Read-only existing fiscal records, not create/edit forms, tax
        // configuration, or other fiscal operations.
        if (!$request->isMethodSafe()) {
            return false;
        }

        return $request->routeIs(
            'fiscal.index', 'fiscal.show', 'fiscal.nfce.xml',
            'fiscal.nfce.danfe', 'fiscal.utilities.auxiliary',
            'fiscal.nfce.inutilizations', 'pdv.nfce.danfe'
        );
    }

    public static function visible(array $context, string $module): bool
    {
        // Unlinked instances retain previous behavior. Suspended/expired
        // users keep their original read-only navigation.
        if (($context['status'] ?? 'disabled') !== 'active') {
            return true;
        }

        return in_array($module, $context['modules'] ?? [], true);
    }
}
