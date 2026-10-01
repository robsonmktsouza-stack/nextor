<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalePayment extends Model
{
    protected $fillable = [
        'installment',
        'amount',
        'due_date',
        'payment_method',
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
}
