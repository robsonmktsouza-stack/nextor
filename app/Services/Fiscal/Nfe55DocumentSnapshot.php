<?php

namespace App\Services\Fiscal;

use App\Models\CompanySetting;
use App\Models\NfeDraft;
use Illuminate\Database\Eloquent\Model;

/** Identifies the persisted inputs checked before reserving a fiscal number. */
final class Nfe55DocumentSnapshot
{
    /** Public fiscal registration only; never certificate paths or credentials. */
    public function issuer(CompanySetting $company): array
    {
        return $company->only([
            'document', 'legal_name', 'trade_name', 'state_registration',
            'municipal_registration', 'state', 'city', 'city_ibge_code',
            'zip_code', 'address', 'address_number', 'address_complement',
            'district', 'crt', 'tax_regime', 'simple_rate', 'cnae_main', 'phone', 'timezone',
        ]);
    }

    /** Content guard persisted at reservation and rechecked by the worker. */
    public function materialFingerprint(NfeDraft $draft, CompanySetting $company): string
    {
        $draft->loadMissing(['items', 'operationNature']);
        $attributes = $this->attributes($draft);
        foreach (array_keys($attributes) as $key) {
            if (in_array($key, [
                'status', 'validated_at', 'document_number', 'numeric_code',
                'access_key', 'protocol', 'xml_path', 'response_path', 'error_message',
                'transmission_attempted_at', 'processed_at', 'authorized_at',
                'created_at', 'updated_at', 'cancelled_at',
            ], true) || str_starts_with($key, 'cancellation_')) unset($attributes[$key]);
        }
        $custom = $draft->custom_fields ?? [];
        unset($custom['_reserved_fiscal_fingerprint']);
        $attributes['custom_fields'] = $custom === [] ? null : $custom;
        ksort($attributes);
        $withoutTimestamps = function ($model): ?array {
            $values = $this->attributes($model);
            if ($values === null) return null;
            unset($values['created_at'], $values['updated_at']);
            ksort($values);
            return $values;
        };

        return $this->digest([
            'draft' => $attributes,
            'items' => $draft->items->map($withoutTimestamps)->all(),
            'nature' => $withoutTimestamps($draft->operationNature),
            'issuer' => $this->issuer($company),
        ]);
    }

    public function fingerprint(NfeDraft $draft, CompanySetting $company): string
    {
        $draft->loadMissing(['items', 'operationNature']);

        // Only a digest leaves this method. Certificate/configuration values
        // are neither stored in a snapshot nor written to application logs.
        return $this->digest([
            'draft' => $this->attributes($draft),
            'items' => $draft->items->map(fn($item) => $this->attributes($item))->all(),
            'nature' => $this->attributes($draft->operationNature),
            'issuer' => $this->attributes($company),
        ]);
    }

    private function attributes(?Model $model): ?array
    {
        if ($model === null) return null;
        $values = $model->getAttributes();
        foreach ($model->getCasts() as $key => $cast) {
            if (array_key_exists($key, $values) && in_array($cast, ['array', 'json'], true)) {
                $values[$key] = $model->getAttribute($key);
            }
        }
        return $values;
    }

    private function digest(array $payload): string
    {
        $normalize = static function (mixed $value) use (&$normalize): mixed {
            if (!is_array($value)) return $value;
            if (!array_is_list($value)) ksort($value);
            foreach ($value as $key => $child) $value[$key] = $normalize($child);
            return $value;
        };
        // MySQL JSON columns may reorder object keys. Compare their semantic
        // content, preserving item/list order, rather than raw JSON strings.
        return hash('sha256', json_encode($normalize($payload), JSON_THROW_ON_ERROR));
    }
}
