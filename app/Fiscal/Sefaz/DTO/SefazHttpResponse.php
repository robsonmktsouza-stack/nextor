<?php

namespace App\Fiscal\Sefaz\DTO;

final readonly class SefazHttpResponse
{
    public function __construct(
        public int $status,
        public string $body,
        public int $durationMs,
    ) {
    }
}
