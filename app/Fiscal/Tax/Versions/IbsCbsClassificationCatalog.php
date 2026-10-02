<?php

namespace App\Fiscal\Tax\Versions;

use App\Fiscal\Tax\Exceptions\TaxConfigurationException;
use App\Fiscal\Tax\ValueObjects\IbsCbsClassification;
use JsonException;

final class IbsCbsClassificationCatalog
{
    /** @var array<string,array{cst:string,code:string,description:string,nfce:bool}>|null */
    private ?array $entries = null;

    public function validate(IbsCbsClassification $classification): void
    {
        $entry = $this->entries()[$classification->code] ?? null;

        if ($entry === null || $entry['cst'] !== $classification->cst || !$entry['nfce']) {
            throw new TaxConfigurationException(
                "cClassTrib {$classification->code} / CST {$classification->cst} não está habilitado "
                .'no catálogo versionado da NFC-e.'
            );
        }
    }

    /**
     * @return array<string,array{cst:string,code:string,description:string,nfce:bool}>
     */
    private function entries(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }

        $path = resource_path('fiscal/tax/ibs-cbs-classification-v1.70.json');

        if (!is_file($path)) {
            throw new TaxConfigurationException('Catálogo versionado de cClassTrib não encontrado.');
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new TaxConfigurationException('Catálogo de cClassTrib contém JSON inválido.', previous: $e);
        }

        $entries = [];
        foreach ($data['entries'] ?? [] as $entry) {
            if (!is_array($entry) || !isset($entry['cst'], $entry['code'], $entry['description'], $entry['nfce'])) {
                throw new TaxConfigurationException('Entrada inválida no catálogo de cClassTrib.');
            }

            $entries[(string) $entry['code']] = [
                'cst' => (string) $entry['cst'],
                'code' => (string) $entry['code'],
                'description' => (string) $entry['description'],
                'nfce' => (bool) $entry['nfce'],
            ];
        }

        return $this->entries = $entries;
    }
}
