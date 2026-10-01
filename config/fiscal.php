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
        'core_package' => 'PL_010f_v1.04',
        'previous_core_package' => '010e_v1.02',
        'cnpj_alphanumeric_package' => '010d_v1.03',
        'rtc_validation_note' => 'NT2025.002_v1.52',
        'sales_operation_validation_note' => 'NT2026.002_v1.11',
        'path' => resource_path('fiscal/schemas'),
    ],
];
