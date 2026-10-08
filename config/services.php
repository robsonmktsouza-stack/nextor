<?php

return [
    'acbr_nfe' => [
        'library_path' => env('ACBr_NFE_LIBRARY_PATH', ''),
        'config_path' => env('ACBr_NFE_CONFIG_PATH', ''),
        'schemas_path' => env('ACBr_NFE_SCHEMAS_PATH', ''),
        'production_enabled' => (bool) env('ACBr_NFE_PRODUCTION_ENABLED', false),
    ],
];
