<?php

namespace App\Fiscal\DTO;

use App\Fiscal\Exceptions\InvalidFiscalDataException;
use App\Fiscal\Models\FiscalCompany;
use DateTimeInterface;
use JsonException;

final readonly class NfceSnapshot
{
    private const IDENTIFICATION_REQUIRED = [
        'nature_operation',
        'operation_type',
        'destination',
        'city_tax_code',
        'print_type',
        'purpose',
        'final_consumer',
        'presence',
        'process',
        'process_version',
    ];

    private const ITEM_REQUIRED = [
        'code',
        'gtin',
        'description',
        'ncm',
        'cfop',
        'unit',
        'quantity',
        'unit_price',
        'gross_total',
        'tax_gtin',
        'tax_unit',
        'tax_quantity',
        'tax_unit_price',
        'include_total',
        'tax',
    ];

    private const ICMS_TOTAL_REQUIRED = [
        'vBC',
        'vICMS',
        'vICMSDeson',
        'vFCP',
        'vBCST',
        'vST',
        'vFCPST',
        'vFCPSTRet',
        'vProd',
        'vFrete',
        'vSeg',
        'vDesc',
        'vII',
        'vIPI',
        'vIPIDevol',
        'vPIS',
        'vCOFINS',
        'vOutro',
        'vNF',
    ];

    private function __construct(private array $payload)
    {
        $this->validate();
    }

    public static function fromResolvedData(
        FiscalCompany $company,
        array $resolved,
        DateTimeInterface $issueAt,
    ): self {
        if (array_key_exists('issuer', $resolved) || array_key_exists('issued_at', $resolved)) {
            throw new InvalidFiscalDataException(
                'Emitente e data de emissão são capturados pelo motor e não podem ser sobrescritos no snapshot.'
            );
        }

        $payload = $resolved;
        $payload['issued_at'] = $issueAt->format('Y-m-d\TH:i:sP');
        $payload['issuer'] = [
            'cnpj' => strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) $company->cnpj) ?? ''),
            'legal_name' => (string) $company->legal_name,
            'trade_name' => $company->trade_name !== null ? (string) $company->trade_name : null,
            'state_registration' => (string) $company->state_registration,
            'crt' => (string) $company->crt,
            'street' => (string) $company->street,
            'number' => (string) $company->number,
            'complement' => $company->complement !== null ? (string) $company->complement : null,
            'district' => (string) $company->district,
            'city_ibge' => (string) $company->city_ibge,
            'city' => (string) $company->city,
            'uf' => strtoupper((string) $company->uf),
            'zip_code' => preg_replace('/\D/', '', (string) $company->zip_code) ?? '',
        ];

        return new self($payload);
    }

    public static function fromJson(string $json): self
    {
        try {
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidFiscalDataException('Snapshot fiscal armazenado não contém JSON válido.', previous: $e);
        }

        if (!is_array($payload)) {
            throw new InvalidFiscalDataException('Snapshot fiscal armazenado possui formato inválido.');
        }

        return new self($payload);
    }

    public function toArray(): array
    {
        return $this->payload;
    }

    public function toJson(): string
    {
        try {
            return json_encode(
                $this->payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
            );
        } catch (JsonException $e) {
            throw new InvalidFiscalDataException('Não foi possível serializar o snapshot fiscal.', previous: $e);
        }
    }

    public function issuer(): array
    {
        return $this->payload['issuer'];
    }

    public function identification(): array
    {
        return $this->payload['identification'];
    }

    /** @return list<array<string,mixed>> */
    public function items(): array
    {
        return $this->payload['items'];
    }

    public function totals(): array
    {
        return $this->payload['totals'];
    }

    /** @return list<array<string,mixed>> */
    public function payments(): array
    {
        return $this->payload['payments'];
    }

    public function recipient(): ?array
    {
        return isset($this->payload['recipient']) && is_array($this->payload['recipient'])
            ? $this->payload['recipient']
            : null;
    }

    public function freightMode(): string
    {
        return (string) $this->payload['freight_mode'];
    }

    public function issuedAt(): string
    {
        return (string) $this->payload['issued_at'];
    }

    public function additionalInfo(): ?string
    {
        $value = $this->payload['additional_info'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function validate(): void
    {
        $this->assertNoFloats($this->payload, 'snapshot');

        foreach (['issuer', 'identification', 'items', 'totals', 'payments', 'freight_mode', 'issued_at'] as $key) {
            if (!array_key_exists($key, $this->payload)) {
                throw new InvalidFiscalDataException("Campo obrigatório '{$key}' ausente no snapshot fiscal.");
            }
        }

        if (!is_array($this->payload['issuer']) || !is_array($this->payload['identification'])) {
            throw new InvalidFiscalDataException('Emitente/identificação do snapshot possuem formato inválido.');
        }

        foreach (
            ['cnpj', 'legal_name', 'state_registration', 'crt', 'street', 'number', 'district', 'city_ibge', 'city', 'uf', 'zip_code']
            as $field
        ) {
            $this->requireScalar($this->payload['issuer'], $field, 'issuer');
        }

        foreach (self::IDENTIFICATION_REQUIRED as $field) {
            $this->requireScalar($this->payload['identification'], $field, 'identification');
        }

        if (!is_array($this->payload['items']) || !array_is_list($this->payload['items'])) {
            throw new InvalidFiscalDataException('Itens do snapshot devem ser uma lista.');
        }

        if (count($this->payload['items']) < 1 || count($this->payload['items']) > 990) {
            throw new InvalidFiscalDataException('A NFC-e deve possuir entre 1 e 990 itens no snapshot.');
        }

        foreach ($this->payload['items'] as $index => $item) {
            if (!is_array($item)) {
                throw new InvalidFiscalDataException("Item {$index} do snapshot possui formato inválido.");
            }

            foreach (self::ITEM_REQUIRED as $field) {
                if ($field === 'tax') {
                    if (!isset($item[$field]) || !is_array($item[$field])) {
                        throw new InvalidFiscalDataException("Campo tax ausente/inválido no item {$index}.");
                    }
                    continue;
                }

                $this->requireScalar($item, $field, "items.{$index}");
            }
        }

        if (
            !is_array($this->payload['totals'])
            || !isset($this->payload['totals']['ICMSTot'])
            || !is_array($this->payload['totals']['ICMSTot'])
        ) {
            throw new InvalidFiscalDataException('Totais devem conter o grupo ICMSTot resolvido.');
        }

        foreach (self::ICMS_TOTAL_REQUIRED as $field) {
            $this->requireScalar($this->payload['totals']['ICMSTot'], $field, 'totals.ICMSTot');
        }

        if (!is_array($this->payload['payments']) || !array_is_list($this->payload['payments'])) {
            throw new InvalidFiscalDataException('Pagamentos do snapshot devem ser uma lista.');
        }

        if (count($this->payload['payments']) < 1 || count($this->payload['payments']) > 100) {
            throw new InvalidFiscalDataException('A NFC-e deve possuir entre 1 e 100 pagamentos no snapshot.');
        }

        foreach ($this->payload['payments'] as $index => $payment) {
            if (!is_array($payment)) {
                throw new InvalidFiscalDataException("Pagamento {$index} possui formato inválido.");
            }

            $this->requireScalar($payment, 'type', "payments.{$index}");
            $this->requireScalar($payment, 'amount', "payments.{$index}");
        }

        if (!is_scalar($this->payload['freight_mode']) || !is_string($this->payload['issued_at'])) {
            throw new InvalidFiscalDataException('Frete/data de emissão do snapshot possuem formato inválido.');
        }
    }

    private function requireScalar(array $data, string $field, string $scope): void
    {
        if (!array_key_exists($field, $data) || !is_scalar($data[$field])) {
            throw new InvalidFiscalDataException("Campo obrigatório '{$scope}.{$field}' ausente ou inválido.");
        }
    }

    private function assertNoFloats(mixed $value, string $path): void
    {
        if (is_float($value)) {
            throw new InvalidFiscalDataException(
                "Valor float não é permitido no snapshot fiscal ({$path}); use string decimal."
            );
        }

        if (!is_array($value)) {
            return;
        }

        foreach ($value as $key => $child) {
            $this->assertNoFloats($child, $path.'.'.$key);
        }
    }
}
