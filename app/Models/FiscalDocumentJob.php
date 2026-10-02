<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalDocumentJob extends Model
{
    protected $fillable=[
        'document_type','sale_id','status','environment','series','document_number',
        'settings_snapshot','source_snapshot','error_message','prepared_at','processed_at',
    ];

    protected function casts(): array
    {
        return [
            'settings_snapshot'=>'array',
            'source_snapshot'=>'array',
            'prepared_at'=>'datetime',
            'processed_at'=>'datetime',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
