<?php

namespace App\Services\License;

use App\Support\ManagedLicenseConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

final class CentralLicenseService
{
    public function state(): array
    {
        if (!ManagedLicenseConfig::enabled()) {
            return ['status' => 'disabled', 'modules' => []];
        }
        // Apply expiry against the local calendar on every access. A cached or
        // offline licence cannot stay active after its signed expiry date.
        return $this->withLocalExpiry(Cache::remember(
            'lumeron:central-license:v1',
            (int) config('central_license.cache_seconds', 60),
            fn (): array => $this->refresh()
        ));
    }

    /** Fetch the remotely authorized license; use only signed data for decisions. */
    public function refresh(): array
    {
        if (!ManagedLicenseConfig::enabled()) {
            return ['status' => 'disabled', 'modules' => []];
        }
        try {
            $settings = ManagedLicenseConfig::settings();
            $url = (string) ($settings['url'] ?? '');
            $id = (string) ($settings['instance_id'] ?? '');
            $secret = (string) ($settings['secret'] ?? '');
            $cnpj = $settings['cnpj'] ?? null;
            if (!preg_match('/^[0-9a-f-]{36}$/i', $id)
                || !preg_match('/^[0-9a-f]{96}$/i', $secret)
                || !$cnpj
                || (parse_url($url, PHP_URL_SCHEME) !== 'https'
                    && !(parse_url($url, PHP_URL_SCHEME) === 'http'
                        && app()->environment('local', 'testing')
                        && in_array(parse_url($url, PHP_URL_HOST), ['localhost', '127.0.0.1', '::1'], true)))) {
                throw new \RuntimeException('Configuração de licença central incompleta.');
            }
            $endpoint = $url.'/api/license/check';
            $body = json_encode([
                'cnpj' => $cnpj,
                'version' => (string) config('app.version', 'v1.0.0'),
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $timestamp = (string) time();
            $nonce = bin2hex(random_bytes(16));
            $canonical = "POST\n/api/license/check\n{$timestamp}\n{$nonce}\n".hash('sha256', $body);
            $requestSignature = hash_hmac('sha256', $canonical, $secret);

            $response = Http::connectTimeout(3)->timeout(5)
                ->withHeaders([
                    'X-Lumeron-Instance' => $id,
                    'X-Lumeron-Timestamp' => $timestamp,
                    'X-Lumeron-Nonce' => $nonce,
                    'X-Lumeron-Signature' => $requestSignature,
                ])->withBody($body, 'application/json')->post($endpoint);
            if (!$response->successful()) {
                throw new \RuntimeException('A central não respondeu com uma licença válida.');
            }
            $raw = $response->body();
            $expected = hash_hmac('sha256', $raw, $secret);
            $provided = strtolower((string) $response->header('X-Lumeron-Signature'));
            if (!preg_match('/^[a-f0-9]{64}$/', $provided) || !hash_equals($expected, $provided)) {
                throw new \RuntimeException('Resposta da licença sem assinatura válida.');
            }

            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($payload)
                || !hash_equals($id, (string) ($payload['instance_id'] ?? ''))
                || !hash_equals($cnpj, (string) ($payload['cnpj'] ?? ''))
                || !in_array($payload['status'] ?? null, ['active', 'suspended', 'expired'], true)
                || !is_array($payload['modules'] ?? null)
                || !preg_match('/^[a-z][a-z0-9_-]{2,29}$/', (string) ($payload['plan'] ?? ''))
                || !isset($payload['checked_at'])
                || abs(now()->diffInSeconds(\Illuminate\Support\Carbon::parse($payload['checked_at']), false)) > 300) {
                throw new \RuntimeException('Resposta da licença incompatível.');
            }
            $expires = $payload['expires_at'] ?? null;
            if ($expires !== null && (!is_string($expires)
                || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires))) {
                throw new \RuntimeException('Validade da licença inválida.');
            }
            // Optional extension for older Control versions. Never trust an
            // unsigned or malformed reminder threshold.
            $noticeDays = $payload['notice_days'] ?? null;
            if ($noticeDays !== null
                && (!is_int($noticeDays) || $noticeDays < 0 || $noticeDays > 365)) {
                throw new \RuntimeException('Prazo de aviso incompatível.');
            }
            $modules = $payload['modules'];
            if (count($modules) > count(\App\Support\CommercialModuleAccess::ALLOWED)
                || count($modules) !== count(array_unique($modules))
                || array_diff($modules, \App\Support\CommercialModuleAccess::ALLOWED)) {
                throw new \RuntimeException('Módulos da licença incompatíveis.');
            }
            DB::table('central_license_states')->updateOrInsert(['id' => 1], [
                'status' => $payload['status'],
                'plan' => $payload['plan'],
                'modules' => json_encode($modules, JSON_THROW_ON_ERROR),
                'expires_at' => $expires,
                'notice_days' => $noticeDays,
                'verified_at' => now(),
                'updated_at' => now(),
            ]);
            $state = ['status' => $payload['status'], 'plan' => $payload['plan'],
                'modules' => $modules, 'expires_at' => $expires,
                'notice_days' => $noticeDays, 'source' => 'online'];
            Cache::put('lumeron:central-license:v1', $state,
                (int) config('central_license.cache_seconds', 60));
            return $this->withLocalExpiry($state);
        } catch (\Throwable $error) {
            // Do not log tokens, HMACs or request bodies; an offline cache is only
            // accepted during a bounded grace period.
            $last = DB::table('central_license_states')->where('id', 1)->first();
            if ($last && now()->diffInHours($last->verified_at, true)
                <= (int) (ManagedLicenseConfig::settings()['grace_hours'] ?? 72)) {
                return $this->withLocalExpiry([
                    'status' => $last->status,
                    'plan' => $last->plan,
                    'modules' => json_decode($last->modules, true) ?: [],
                    'expires_at' => $last->expires_at,
                    'notice_days' => $last->notice_days === null ? null : (int) $last->notice_days,
                    'source' => 'offline-grace',
                ]);
            }
            return ['status' => 'unverified', 'modules' => [], 'source' => 'offline'];
        }
    }

    private function withLocalExpiry(array $state): array
    {
        $date = $state['expires_at'] ?? null;
        if (($state['status'] ?? null) === 'active' && is_string($date)
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            && $date < now()->toDateString()) {
            $state['status'] = 'expired';
        }

        return $state;
    }
}
