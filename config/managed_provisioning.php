<?php

return [
    // Must be explicitly enabled on the trusted VPS, never in Laragon or CI.
    // The HTTP app never executes privileged provisioning operations.
    'enabled' => (bool) env('LUMERON_MANAGED_PROVISIONING', false),
    'source' => env('LUMERON_ERP_RELEASE_SOURCE', ''),
    'control_path' => env('LUMERON_CONTROL_RELEASE_PATH', ''),
    'control_url' => env('LUMERON_CONTROL_PUBLIC_URL', ''),
    'base_domain' => env('LUMERON_CUSTOMER_BASE_DOMAIN', 'lumeron.com.br'),
    'php_version' => env('LUMERON_CUSTOMER_PHP_VERSION', '8.3'),
    'mysql_admin_cnf' => env('LUMERON_MYSQL_ADMIN_CNF', '/root/.my.cnf'),
    'tls_cert_name' => env('LUMERON_CUSTOMER_TLS_CERT_NAME', ''),
    'send_invite' => (bool) env('LUMERON_MANAGED_INVITE_EMAIL', false),
    // Laragon local only. Reuses the queued company; never touches VPS.
    'local_enabled' => (bool) env('LUMERON_LOCAL_PROVISIONING', false),
    'local_source' => env('LUMERON_LOCAL_ERP_SOURCE', ''),
    'local_control_url' => env('LUMERON_LOCAL_CONTROL_URL', ''),
];
