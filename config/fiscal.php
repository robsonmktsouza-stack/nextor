<?php

return [
    'issuer_name' => env('FISCAL_ISSUER_NAME', env('APP_NAME', 'Nextor')),
    'issuer_cnpj' => env('FISCAL_ISSUER_CNPJ'),
    'issuer_address' => env('FISCAL_ISSUER_ADDRESS'),
];
