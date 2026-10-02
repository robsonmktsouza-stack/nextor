<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialSettlement extends Model
{
    protected $fillable=[
        'financial_entry_id','financial_account_id','user_id','amount','settled_at',
        'payment_method','notes','reversed_at','reversal_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount'=>'decimal:2',
            'settled_at'=>'date',
            'reversed_at'=>'datetime',
        ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(FinancialEntry::class,'financial_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class,'financial_account_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
