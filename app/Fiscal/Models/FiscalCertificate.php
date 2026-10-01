<?php

namespace App\Fiscal\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalCertificate extends Model
{
    protected $fillable = [
        'fiscal_company_id', 'pfx_payload', 'pfx_password', 'subject', 'issuer',
        'serial_number', 'fingerprint_sha256', 'subject_document',
        'valid_from', 'valid_to', 'is_active',
    ];

    protected $hidden = [
        'pfx_payload',
        'pfx_password',
    ];

    protected function casts(): array
    {
        return [
            'pfx_payload' => 'encrypted',
            'pfx_password' => 'encrypted',
            'valid_from' => 'datetime',
            'valid_to' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(FiscalCompany::class, 'fiscal_company_id');
    }

    public function pfxBytes(): string
    {
        return base64_decode((string) $this->pfx_payload, true) ?: '';
    }
}
