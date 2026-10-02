<?php

return [
    'sefaz' => [
        'connect_timeout_ms' => (int) env('FISCAL_SEFAZ_CONNECT_TIMEOUT_MS', 5000),
        'request_timeout_ms' => (int) env('FISCAL_SEFAZ_REQUEST_TIMEOUT_MS', 15000),
    ],
];
