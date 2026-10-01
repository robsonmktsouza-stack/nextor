<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Compatibilidade do comprovante não fiscal atual
    |--------------------------------------------------------------------------
    | Estes campos continuam existindo enquanto o PDV ainda usa o template
    | não fiscal. O motor fiscal real usa configuração por empresa no banco.
    */
    'issuer_name' => env('FISCAL_ISSUER_NAME', env('APP_NAME', 'Nextor')),
    'issuer_cnpj' => env('FISCAL_ISSUER_CNPJ'),
    'issuer_address' => env('FISCAL_ISSUER_ADDRESS'),

    /*
    |--------------------------------------------------------------------------
    | Nextor Fiscal Engine
    |--------------------------------------------------------------------------
    */
    'engine' => [
        'document_model' => '65',
        'layout_version' => '4.00',
        'default_environment' => env('FISCAL_ENVIRONMENT', '2'),
        'production_enabled' => (bool) env('FISCAL_PRODUCTION_ENABLED', false),
    ],

    'schemas' => [
        'audited_at' => '2026-10-01',
        'core_package' => '010e_v.1.02',
        'cnpj_alphanumeric_package' => '010d_v.1.03',
        'rtc_event_package' => 'NT2025.002_v1.40_events',
        'path' => resource_path('fiscal/schemas'),
    ],
];
