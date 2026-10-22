<?php

namespace App\Support;

use Illuminate\Http\Request;

final class LoginSubdomain
{
    public const RESERVED = [
        'admin', 'api', 'app', 'assets', 'auth', 'billing', 'control',
        'dev', 'ftp', 'help', 'login', 'mail', 'portal', 'smtp',
        'static', 'status', 'support', 'suporte', 'www',
    ];

    public static function valid(string $slug): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9-]{0,37}[a-z0-9]$/', $slug) === 1
            && !str_contains($slug, '--')
            && !in_array($slug, self::RESERVED, true);
    }

    public static function current(): string
    {
        $configured=strtolower(trim((string)(config('instance.subdomain') ?: str_replace('_','-',(string)config('instance.id', '')))));
        return self::valid($configured) ? $configured : 'local';
    }

    public static function isPortal(): bool
    {
        return (bool)config('instance.login_portal', false);
    }

    public static function portalOrigin(): string
    {
        return 'https://'.(string)config('instance.login_portal_host', 'lumeron.com.br');
    }

    public static function tenantUrl(string $slug): ?string
    {
        $slug=strtolower(trim($slug));
        if (!self::valid($slug)) return null;
        $domain=strtolower(trim((string)config('instance.base_domain', 'lumeron.com.br')));
        if (!preg_match('/^[a-z0-9]+(?:[.-][a-z0-9]+)*\.[a-z]{2,}$/', $domain)) return null;
        return 'https://'.$slug.'.'.$domain;
    }

    public static function fromTrustedPortal(Request $request): bool
    {
        if (self::isPortal() || !config('instance.subdomain')) return false;
        if ($request->headers->get('Origin') !== self::portalOrigin()) return false;
        // Require the tenant's real hostname. No wildcard CORS.
        $expectedHost=self::tenantUrl(self::current());
        return $expectedHost !== null
            && strtolower($request->getSchemeAndHttpHost()) === $expectedHost;
    }

    public static function corsHeaders(): array
    {
        return [
            'Access-Control-Allow-Origin'=>self::portalOrigin(),
            'Access-Control-Allow-Credentials'=>'true',
            'Access-Control-Allow-Methods'=>'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers'=>'Content-Type, Accept, X-CSRF-TOKEN, X-Requested-With',
            'Vary'=>'Origin',
            'Cache-Control'=>'no-store, private',
        ];
    }
}
