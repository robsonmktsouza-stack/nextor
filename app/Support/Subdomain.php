<?php
namespace App\Support;

/** Stable identifier; never a password or a company name requirement. */
final class Subdomain
{
    public const RESERVED = [
        'admin', 'api', 'app', 'assets', 'auth', 'billing', 'control',
        'dev', 'ftp', 'help', 'login', 'mail', 'portal', 'smtp',
        'static', 'status', 'support', 'suporte', 'www',
    ];

    public static function normalize(string $value): string
    {
        $value = strtolower(trim($value));
        if (preg_match('/^[0-9][0-9.\/-]*$/', $value)) {
            $digits = preg_replace('/\D/', '', $value);
            if (strlen($digits) === 14) return $digits;
        }
        return $value;
    }

    public static function valid(string $value): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9-]{0,37}[a-z0-9]$/', $value) === 1
            && !str_contains($value, '--')
            && !in_array($value, self::RESERVED, true);
    }
}
