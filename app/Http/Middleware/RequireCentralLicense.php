<?php

namespace App\Http\Middleware;

use App\Services\License\CentralLicenseService;
use App\Support\CommercialLicensePolicy;
use App\Support\CommercialModuleAccess;
use App\Support\ManagedLicenseConfig;
use Closure;
use Illuminate\Http\Request;

final class RequireCentralLicense
{
    public function handle(Request $request, Closure $next)
    {
        if (!ManagedLicenseConfig::enabled() || !$request->user()) {
            return $next($request);
        }

        $state = app(CentralLicenseService::class)->state();
        $status = (string) ($state['status'] ?? 'unverified');
        $readonly = CommercialLicensePolicy::isReadOnly($state);

        // Compartilhado com os dois layouts, sem uma segunda consulta nem
        // exposição de credenciais no HTML.
        $request->attributes->set('lumeron_commercial_license', [
            'status' => $status,
            'readonly' => $readonly,
            'modules' => $state['modules'] ?? [],
            'source' => $state['source'] ?? null,
            'title' => CommercialLicensePolicy::title($status),
            'explanation' => CommercialLicensePolicy::explanation($status),
            'denied' => CommercialLicensePolicy::deniedOperationMessage($status),
        ]);

        if ($readonly) {
            // Consultas, relatórios e impressão preservados; ações mutáveis
            // são negadas pelo SERVIDOR, inclusive requisições diretas/AJAX.
            if (!CommercialLicensePolicy::canWrite($request)) {
                $message = CommercialLicensePolicy::deniedOperationMessage($status);
                if ($request->expectsJson() || $request->ajax()) {
                    return response()->json([
                        'message' => $message,
                        'code' => 'LUMERON_LICENSE_READ_ONLY',
                        'license_status' => $status,
                    ], 423);
                }

                return redirect()->back(303)->with('warning', $message);
            }

            return $next($request);
        }

        if ($status !== 'active') {
            return $next($request);
        }

        $required = CommercialModuleAccess::forRequest($request);
        if ($required !== null && !in_array($required, $state['modules'] ?? [], true)) {
            $message = 'Este módulo não está incluído no plano contratado.';
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'message' => $message,
                    'code' => 'LUMERON_MODULE_UNAVAILABLE',
                    'module' => $required,
                ], 403);
            }

            abort(403, $message);
        }

        return $next($request);
    }
}
