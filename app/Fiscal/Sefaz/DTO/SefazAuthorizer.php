<?php

namespace App\Fiscal\Sefaz\DTO;

final readonly class SefazAuthorizer
{
    public function __construct(
        public string $code,
        public string $name,
    ) {
    }
}
