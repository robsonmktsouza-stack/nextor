<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalDocumentJob extends Model
{
    protected $fillable=[
        'document_type','sale_id','status','emission_mode','contingency_reason','contingency_started_at',
        'environment','series','document_number','access_key','protocol','authorized_at',
        'cancellation_status','cancellation_reason','cancellation_requested_at','cancelled_at',
        'settings_snapshot','source_snapshot','error_message','prepared_at','processed_at',
        'xml_path','response_path',
    ];

    protected function casts(): array
    {
        return [
            'settings_snapshot'=>'array',
            'source_snapshot'=>'array',
            'contingency_started_at'=>'datetime',
            'authorized_at'=>'datetime',
            'cancellation_requested_at'=>'datetime',
            'cancelled_at'=>'datetime',
            'prepared_at'=>'datetime',
            'processed_at'=>'datetime',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
