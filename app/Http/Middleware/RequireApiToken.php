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

        return $next($request);
    }
}
