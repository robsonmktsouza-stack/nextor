<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Support\Cnpj;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

final class LicenseCheckController extends Controller
{
    public function __invoke(Request $request)
    {
        $id = (string) $request->header('X-Lumeron-Instance', '');
        $stamp = (string) $request->header('X-Lumeron-Timestamp', '');
        $nonce = (string) $request->header('X-Lumeron-Nonce', '');
        $provided = (string) $request->header('X-Lumeron-Signature', '');

        if (!preg_match('/^[0-9a-f-]{36}$/i', $id)
            || !ctype_digit($stamp) || abs(time() - (int) $stamp) > 300
            || !preg_match('/^[a-f0-9]{32}$/i', $nonce)
            || !preg_match('/^[a-f0-9]{64}$/i', $provided)) {
            return response()->json(['message' => 'Não autorizado.'], 401);
        }

        $company = Company::where('instance_id', $id)->first();
        if (!$company) {
            return response()->json(['message' => 'Não autorizado.'], 401);
        }
        $raw = $request->getContent();
        $canonical = "POST\n/api/license/check\n{$stamp}\n{$nonce}\n".hash('sha256', $raw);
        $expected = hash_hmac('sha256', $canonical, $company->license_secret);
        if (!hash_equals($expected, strtolower($provided))) {
            return response()->json(['message' => 'Não autorizado.'], 401);
        }
        if (!Cache::add('license-nonce:'.hash('sha256', $id.$nonce), true, 300)) {
            return response()->json(['message' => 'Requisição repetida.'], 409);
        }
        $payload = json_decode($raw, true);
        if (!is_array($payload)
            || !isset($payload['cnpj']) || Cnpj::digits((string) $payload['cnpj']) !== $company->cnpj
            || (isset($payload['version']) && (!is_string($payload['version']) || strlen($payload['version']) > 50))) {
            return response()->json(['message' => 'Instalação incompatível.'], 422);
        }
        $company->forceFill([
            'last_seen_at' => now(),
            'last_version' => (string) ($payload['version'] ?? ''),
        ])->save();

        $data = [
            'instance_id' => $company->instance_id,
            'cnpj' => $company->cnpj,
            'status' => $company->currentStatus(),
            'plan' => $company->plan,
            'modules' => $company->modules ?? [],
            'expires_at' => $company->expires_at?->toDateString(),
            'checked_at' => now()->toIso8601String(),
        ];
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return response($json, 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Lumeron-Signature' => hash_hmac('sha256', $json, $company->license_secret),
        ]);
    }
}
