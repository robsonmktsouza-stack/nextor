<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class NFCeRenumberHistory extends Model
{
    protected $fillable = [
        'fiscal_document_job_id', 'old_series', 'old_number',
        'old_access_key', 'old_signed_xml_path', 'old_sefaz_response_path',
        'old_consult_response_path', 'old_error_message', 'new_number',
    ];

    public function fiscalDocument(): BelongsTo
    {
        return $this->belongsTo(FiscalDocumentJob::class, 'fiscal_document_job_id');
    }
}
