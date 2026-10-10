<?php

return [
    // One paid installation per CNPJ; intentionally no shared-company selector.
    // Empty outside managed VPS installations so Laragon/dev remains unchanged.
    'id'=>env('LUMERON_INSTANCE_ID'),
    'cnpj'=>preg_replace('/\D/', '', (string)env('LUMERON_INSTANCE_CNPJ', '')),
];
