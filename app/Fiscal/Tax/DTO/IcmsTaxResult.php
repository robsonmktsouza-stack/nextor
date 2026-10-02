<?php

namespace App\Fiscal\Tax\DTO;

use App\Fiscal\Tax\Enums\IcmsCsosn;

final readonly class IcmsTaxResult
{
    public function __construct(
        public string $origin,
        public IcmsCsosn $csosn,
    ) {
    }

    public function toXmlArray(): array
    {
        return [
            $this->csosn->xmlGroup() => [
                'orig' => $this->origin,
                'CSOSN' => $this->csosn->value,
            ],
        ];
    }
}
