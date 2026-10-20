<?php

namespace App\Http\Middleware;

use App\Services\License\CentralLicenseService;
use Closure;
use Illuminate\Http\Request;

final class RequireCentralLicense
{
    public function handle(Request $request, Closure $next)
    {
        if (!config('central_license.enabled') || !$request->user()
            || $request->routeIs('license.status', 'logout')) {
            return $next($request);
        }

        // Fiscal/legal continuity: status consultation, authorized XML/DANFE,
        // cancellation and contingency remain accessible even if suspended.
        if ($request->is('fiscal', 'fiscal/*', 'pdv/nfce/*', 'pdv/receipt/*')
            || $request->routeIs('pdv.nfce.*', 'pdv.receipt', 'sales.receipt')) {
            return $next($request);
        }

        $state = app(CentralLicenseService::class)->state();
        $status = $state['status'] ?? 'unverified';
        if ($status !== 'active') {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'A licença desta instalação não está ativa.'], 423);
            }
            return redirect()->route('license.status');
        }

        // Only commercial modules are restricted here. Fiscal regularization
        // remains exempt; every other permission inside ERP is still enforced.
        $modules = $state['modules'] ?? [];
        $required = match (true) {
            $request->is('pdv', 'pdv/*', 'sales', 'sales/*') => 'pdv',
            $request->is('finance', 'finance/*') => 'finance',
            $request->is('products', 'products/*', 'stock', 'stock/*') => 'products',
            $request->is('services', 'services/*') => 'services',
            default => null,
        };
        if ($required !== null && !in_array($required, $modules, true)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Este módulo não está incluído na licença.'], 403);
            }
            return redirect()->route('license.status');
        }

        return $next($request);
    }
}
