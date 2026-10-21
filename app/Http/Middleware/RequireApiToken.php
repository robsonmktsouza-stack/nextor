<?php

namespace App\Http\Middleware;

use App\Models\AppSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        if(!(bool)AppSetting::value('integrations','api_enabled',false)) {
            return response()->json(['message'=>'API desativada.'],403);
        }

        $expected=(string)AppSetting::value('integrations','api_token','');
        $provided=(string)$request->bearerToken();

        if($expected==='' || $provided==='' || !hash_equals($expected,$provided)) {
            return response()->json(['message'=>'Token inválido.'],401);
        }

        // Token API is outside the authenticated web middleware group.
        // It must respect the same signed commercial licence as the UI.
        if (\App\Support\ManagedLicenseConfig::enabled()) {
            $state = app(\App\Services\License\CentralLicenseService::class)->state();
            $module = match ((string) $request->segment(3)) {
                'products' => 'products',
                'sales' => 'pdv',
                default => null,
            };
            if ($state['status'] === 'active' && $module !== null
                && !in_array($module, $state['modules'] ?? [], true)) {
                return response()->json([
                    'message' => 'Este módulo não está incluído no plano contratado.',
                    'code' => 'LUMERON_MODULE_UNAVAILABLE',
                ], 403);
            }
        }

        return $next($request);
    }
}
