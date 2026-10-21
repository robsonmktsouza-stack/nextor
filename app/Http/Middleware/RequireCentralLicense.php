<?php

namespace App\Http\Middleware;

use App\Services\License\CentralLicenseService;
use App\Support\CommercialLicensePolicy;
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

        // Em licença ativa, os módulos contratados continuam sendo exigidos.
        // Documentos e regularizações fiscais respeitam permissões do usuário.
        $modules = $state['modules'] ?? [];
        $required = match (true) {
            $request->is('pdv', 'pdv/*', 'sales', 'sales/*') => 'pdv',
            $request->is('finance', 'finance/*') => 'finance',
            $request->is('products', 'products/*', 'stock', 'stock/*') => 'products',
            $request->is('services', 'services/*') => 'services',
            default => null,
        };

        if ($required !== null && !in_array($required, $modules, true)) {
            $message = 'Este módulo não está incluído no plano contratado.';
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['message' => $message, 'code' => 'LUMERON_MODULE_UNAVAILABLE'], 403);
            }
            return redirect()->route('license.status')->with('warning', $message);
        }

        return $next($request);
    }
}
