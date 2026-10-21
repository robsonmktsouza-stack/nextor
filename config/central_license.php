<?php

return [
    // Disabled in existing installations until the owner configures each CNPJ.
    'enabled' => filter_var(env('LUMERON_CONTROL_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'managed_file' => storage_path('app/private/lumeron-control.json'),
    'url' => rtrim((string) env('LUMERON_CONTROL_URL', ''), '/'),
    'instance_id' => (string) env('LUMERON_CONTROL_INSTANCE_ID', ''),
    'secret' => (string) env('LUMERON_CONTROL_SECRET', ''),
    'grace_hours' => max(0, (int) env('LUMERON_CONTROL_GRACE_HOURS', 72)),
    'cache_seconds' => 300,
];
