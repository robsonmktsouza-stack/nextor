<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class IncomingNfeManifestationAttempt extends Model
{
    protected $fillable = [
        'event_code', 'status', 'reason', 'sefaz_status', 'protocol',
        'response_message', 'user_id', 'verification_checked_at',
    ];

    protected function casts(): array
    {
        return ['verification_checked_at'=>'datetime'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(IncomingNfe::class, 'incoming_nfe_id');
    }
}
