<?php

namespace App\Fiscal\Tax\DTO;

use App\Fiscal\Tax\Exceptions\TaxConfigurationException;

final readonly class NfceRecipientInput
{
    public function __construct(
        public string $documentType,
        public string $document,
        public string $ieIndicator = '9',
        public ?string $name = null,
        public ?string $stateRegistration = null,
        public ?string $email = null,
    ) {
        if (!in_array($documentType, ['CPF', 'CNPJ'], true)) {
            throw new TaxConfigurationException('Primeiro cenário NFC-e aceita destinatário CPF ou CNPJ.');
        }

        $normalized = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $document) ?? '');

        if ($documentType === 'CPF' && !preg_match('/^\d{11}$/', $normalized)) {
            throw new TaxConfigurationException('CPF do destinatário deve possuir 11 dígitos.');
        }

        if ($documentType === 'CNPJ' && !preg_match('/^[A-Z0-9]{12}\d{2}$/', $normalized)) {
            throw new TaxConfigurationException('CNPJ do destinatário possui formato inválido.');
        }

        if (!in_array($ieIndicator, ['1', '2', '9'], true)) {
            throw new TaxConfigurationException('Indicador de IE do destinatário inválido.');
        }
    }

    public function toSnapshotArray(): array
    {
        $data = [
            'document_type' => $this->documentType,
            'document' => strtoupper(preg_replace('/[^A-Z0-9]/i', '', $this->document) ?? ''),
            'ie_indicator' => $this->ieIndicator,
        ];

        if ($this->name !== null && $this->name !== '') {
            $data['name'] = $this->name;
        }

        if ($this->stateRegistration !== null && $this->stateRegistration !== '') {
            $data['state_registration'] = $this->stateRegistration;
        }

        if ($this->email !== null && $this->email !== '') {
            $data['email'] = $this->email;
        }

        return $data;
    }
}
