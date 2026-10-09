<?php

return [
    'chromium' => [
        // Optional: auto-detected on Windows (Chrome/Edge) and Linux if empty.
        'path' => env('CHROME_BIN', ''),
    ],
    'acbr_nfe' => [
        'library_path' => env('ACBr_NFE_LIBRARY_PATH', ''),
        'config_path' => env('ACBr_NFE_CONFIG_PATH', ''),
        'schemas_path' => env('ACBr_NFE_SCHEMAS_PATH', ''),
    ],
];
