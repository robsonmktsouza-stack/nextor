<?php

use Illuminate\Support\Str;

return [
    'driver' => env('SESSION_DRIVER', 'database'),

    // Sessões administrativas expiram por inatividade e também possuem um
    // limite absoluto. O middleware EnforceSessionSecurity aplica ambos.
    'lifetime' => (int) env('SESSION_LIFETIME', 90),
    'idle_timeout' => (int) env('SESSION_IDLE_TIMEOUT', 90),
    'absolute_timeout' => (int) env('SESSION_ABSOLUTE_TIMEOUT', 480),
    'rotate_every' => (int) env('SESSION_ROTATE_EVERY', 30),

    // Por padrão o acesso termina ao fechar o navegador. Ambientes que
    // realmente precisem preservar a sessão devem declarar isso no .env.
    'expire_on_close' => (bool) env('SESSION_EXPIRE_ON_CLOSE', true),
    'encrypt' => (bool) env('SESSION_ENCRYPT', true),
    'files' => storage_path('framework/sessions'),
    'connection' => env('SESSION_CONNECTION'),
    'table' => env('SESSION_TABLE', 'sessions'),
    'store' => env('SESSION_STORE'),
    'lottery' => [2, 100],
    'cookie' => env('SESSION_COOKIE', Str::slug((string) env('APP_NAME', 'away')).'-session'),
    'path' => env('SESSION_PATH', '/'),
    'domain' => env('SESSION_DOMAIN'),
    'secure' => env('SESSION_SECURE_COOKIE', env('APP_ENV', 'production') === 'production'),
    'http_only' => (bool) env('SESSION_HTTP_ONLY', true),
    'same_site' => env('SESSION_SAME_SITE', 'lax'),
    'partitioned' => (bool) env('SESSION_PARTITIONED_COOKIE', false),
];
