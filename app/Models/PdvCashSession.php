<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PdvCashSession extends Model
{
    protected $fillable=[
        'user_id','opening_amount','closing_amount','expected_amount','difference_amount','closing_breakdown','opening_notes','closing_notes','opened_at','closed_at','closed_by','reviewed_by','reviewed_at','review_notes','register_id','previous_session_id','handover_to',
    ];

    protected function casts(): array
    {
        return [
            'opening_amount'=>'decimal:2',
            'closing_amount'=>'decimal:2',
            'expected_amount'=>'decimal:2',
            'difference_amount'=>'decimal:2',
            'closing_breakdown'=>'array',
            'reviewed_at'=>'datetime',
            'opened_at'=>'datetime',
            'closed_at'=>'datetime',
        ];
    }

    public function register(): BelongsTo { return $this->belongsTo(PdvCashRegister::class,'register_id'); }
    public function previousSession(): BelongsTo { return $this->belongsTo(self::class,'previous_session_id'); }
    public function incomingOperator(): BelongsTo { return $this->belongsTo(User::class,'handover_to'); }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sales(): HasMany { return $this->hasMany(Sale::class,'pdv_cash_session_id'); }

    public function closer(): BelongsTo { return $this->belongsTo(User::class,'closed_by'); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class,'reviewed_by'); }

    public function movements(): HasMany
    {
        return $this->hasMany(PdvCashMovement::class,'cash_session_id');
    }

    public function scopeOpen($query)
    {
        return $query->whereNull('closed_at');
    }
}
