<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PdvCashSession extends Model
{
    protected $fillable=[
        'user_id','opening_amount','closing_amount','opening_notes','closing_notes','opened_at','closed_at',
    ];

    protected function casts(): array
    {
        return [
            'opening_amount'=>'decimal:2',
            'closing_amount'=>'decimal:2',
            'opened_at'=>'datetime',
            'closed_at'=>'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeOpen($query)
    {
        return $query->whereNull('closed_at');
    }
}
