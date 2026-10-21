<?php

namespace App\Support;

use App\Models\CompanySetting;

/**
 * Deployment-managed license identity. Provisioning writes this private file;
 * administrators of customer ERP never need to copy tokens into the UI or .env.
 * The file is never reachable from /public and is not committed to Git.
 */
final class ManagedLicenseConfig
{
    public static function credentials(): ?array
    {
        $file = (string) config('central_license.managed_file', storage_path('app/private/lumeron-control.json'));
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }

        try {
            $item = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($item)
                || !preg_match('/^[0-9a-f-]{36}$/i', (string) ($item['instance_id'] ?? ''))
                || !preg_match('/^[0-9a-f]{96}$/i', (string) ($item['secret'] ?? ''))
                || !preg_match('/^[0-9]{14}$/', (string) ($item['cnpj'] ?? ''))) {
                return null;
            }
            $url = rtrim((string) ($item['url'] ?? ''), '/');
            $scheme = parse_url($url, PHP_URL_SCHEME);
            $host = parse_url($url, PHP_URL_HOST);
            if ($scheme !== 'https'
                && !($scheme === 'http' && app()->environment('local', 'testing')
                    && in_array($host, ['localhost', '127.0.0.1', '::1'], true))) {
                return null;
            }

            $configured = InstanceIdentity::cnpj();
            // The authoritative CNPJ for a legacy local installation comes
            // from the ERP company record, never solely from an untrusted file.
            $registered = preg_replace('/\D/', '', (string) CompanySetting::query()->value('document'));
            if (strlen($registered) !== 14
                || !hash_equals($registered, $item['cnpj'])
                || ($configured && !hash_equals($configured, $registered))) {
                return null;
            }

            return [
                'url' => $url,
                'instance_id' => strtolower($item['instance_id']),
                'secret' => strtolower($item['secret']),
                'cnpj' => $registered,
                'grace_hours' => max(0, min(168, (int) ($item['grace_hours'] ?? 72))),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    public static function enabled(): bool
    {
        if ((bool) config('central_license.enabled') || self::credentials() !== null) {
            return true;
        }

        // A formerly linked installation must not become unrestricted simply
        // because someone removes its private credentials file.
        try {
            return \Illuminate\Support\Facades\DB::table('central_license_states')
                ->where('id', 1)->exists();
        } catch (\Throwable) {
            return false; // Older installs, not yet enrolled or migrated.
        }
    }

    public static function settings(): array
    {
        return self::credentials() ?? [
            'url' => (string) config('central_license.url'),
            'instance_id' => (string) config('central_license.instance_id'),
            'secret' => (string) config('central_license.secret'),
            'cnpj' => InstanceIdentity::cnpj(),
            'grace_hours' => (int) config('central_license.grace_hours', 72),
        ];
    }
}
