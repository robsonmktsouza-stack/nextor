<?php

namespace App\Fiscal\Tax\DTO;

use App\Fiscal\Tax\Enums\PaymentIntegrationType;

final readonly class PaymentCardInput
{
    public function __construct(
        public PaymentIntegrationType $integrationType,
        public ?string $institutionCnpj = null,
        public ?string $brand = null,
        public ?string $authorization = null,
        public ?string $recipientCnpj = null,
        public ?string $terminalId = null,
    ) {
    }

    public function toXmlArray(): array
    {
        $data = [
            'tpIntegra' => $this->integrationType->value,
        ];

        foreach ([
            'CNPJ' => $this->institutionCnpj,
            'tBand' => $this->brand,
            'cAut' => $this->authorization,
            'CNPJReceb' => $this->recipientCnpj,
            'idTermPag' => $this->terminalId,
        ] as $field => $value) {
            if ($value !== null && $value !== '') {
                $data[$field] = $value;
            }
        }

        return $data;
    }
}
