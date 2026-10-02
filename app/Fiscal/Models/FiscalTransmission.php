<?php

namespace App\Fiscal\Models;

use App\Fiscal\Enums\FiscalEnvironment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalTransmission extends Model
{
    protected $fillable = [
        'fiscal_company_id',
        'fiscal_document_id',
        'service',
        'environment',
        'uf',
        'authorizer',
        'endpoint_key',
        'attempt_uuid',
        'started_at',
        'finished_at',
        'duration_ms',
        'http_status',
        'transport_status',
        'fiscal_status',
        'c_stat',
        'x_motivo',
        'request_sha256',
        'response_sha256',
        'request_payload',
        'response_payload',
        'error_class',
        'error_message',
    ];

    protected $hidden = [
        'request_payload',
        'response_payload',
    ];

    protected function casts(): array
    {
        return [
            'environment' => FiscalEnvironment::class,
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'duration_ms' => 'integer',
            'http_status' => 'integer',
            'request_payload' => 'encrypted',
            'response_payload' => 'encrypted',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(FiscalCompany::class, 'fiscal_company_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class, 'fiscal_document_id');
    }
}
