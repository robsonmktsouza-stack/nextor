<?php

return [
    // One paid installation per CNPJ; intentionally no shared-company selector.
    // Empty outside managed VPS installations so Laragon/dev remains unchanged.
    'id'=>env('LUMERON_INSTANCE_ID'),
    'subdomain'=>env('LUMERON_INSTANCE_SUBDOMAIN'),
    'base_domain'=>env('LUMERON_BASE_DOMAIN', 'lumeron.com.br'),
    'login_portal_host'=>env('LUMERON_LOGIN_PORTAL_HOST', 'login.lumeron.com.br'),
    'login_portal'=>env('LUMERON_LOGIN_PORTAL', false),
    'cnpj'=>preg_replace('/\D/', '', (string)env('LUMERON_INSTANCE_CNPJ', '')),
];
