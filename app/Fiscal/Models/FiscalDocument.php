<?php

namespace App\Fiscal\Models;

use App\Fiscal\DTO\NfceSnapshot;
use App\Fiscal\Enums\FiscalDocumentState;
use App\Fiscal\Enums\FiscalEnvironment;
use App\Fiscal\Exceptions\ImmutableFiscalDocumentException;
use App\Models\Sale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalDocument extends Model
{
    private const IMMUTABLE_FIELDS = [
        'fiscal_company_id',
        'sale_id',
        'model',
        'series',
        'number',
        'access_key',
        'environment',
        'emission_type',
        'numeric_code',
        'layout_version',
        'issue_at',
        'snapshot_payload',
        'snapshot_sha256',
    ];

    protected $fillable = [
        'fiscal_company_id', 'sale_id', 'model', 'series', 'number', 'access_key',
        'environment', 'emission_type', 'numeric_code', 'state', 'layout_version',
        'issue_at', 'c_stat', 'x_motivo', 'protocol', 'authorized_at',
        'snapshot_payload', 'snapshot_sha256',
        'xml_generated', 'xml_signed', 'xml_protocolled',
        'contingency_at', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'environment' => FiscalEnvironment::class,
            'state' => FiscalDocumentState::class,
            'series' => 'integer',
            'number' => 'integer',
            'emission_type' => 'integer',
            'issue_at' => 'immutable_datetime',
            'authorized_at' => 'immutable_datetime',
            'contingency_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $document): void {
            foreach (self::IMMUTABLE_FIELDS as $field) {
                if ($document->isDirty($field)) {
                    throw new ImmutableFiscalDocumentException(
                        "O campo fiscal imutável '{$field}' não pode ser alterado após a criação do documento."
                    );
                }
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(FiscalCompany::class, 'fiscal_company_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function snapshot(): NfceSnapshot
    {
        return NfceSnapshot::fromJson($this->snapshot_payload);
    }

    public function snapshotIntegrityIsValid(): bool
    {
        return hash_equals(
            $this->snapshot_sha256,
            hash('sha256', $this->snapshot_payload),
        );
    }
}
