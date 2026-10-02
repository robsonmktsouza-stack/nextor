<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SalePayment extends Model
{
    protected $fillable = [
        'installment',
        'amount',
        'due_date',
        'payment_method',
        'integration_type',
        'transaction_document',
        'transaction_state',
        'institution_document',
        'card_brand',
        'authorization_code',
        'beneficiary_document',
        'terminal_id',
        'receivable',
    ];

    protected function casts(): array
    {
        return [
            'amount'=>'decimal:2',
            'due_date'=>'date',
            'receivable'=>'boolean',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function financialEntry(): HasOne
    {
        return $this->hasOne(FinancialEntry::class);
    }
}
