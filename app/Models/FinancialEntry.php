<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinancialEntry extends Model
{
    protected $fillable=[
        'type','status','category_id','customer_id','sale_id','sale_payment_id','created_by',
        'description','document_number','issue_date','due_date','amount','paid_amount',
        'payment_method','notes','cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'issue_date'=>'date',
            'due_date'=>'date',
            'amount'=>'decimal:2',
            'paid_amount'=>'decimal:2',
            'cancelled_at'=>'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinancialCategory::class,'category_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function salePayment(): BelongsTo
    {
        return $this->belongsTo(SalePayment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class,'created_by');
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(FinancialSettlement::class)->orderByDesc('settled_at')->orderByDesc('id');
    }

    public function activeSettlements(): HasMany
    {
        return $this->hasMany(FinancialSettlement::class)->whereNull('reversed_at');
    }

    public function getBalanceAttribute(): float
    {
        return max(0,round((float)$this->amount-(float)$this->paid_amount,2));
    }

    public function getDisplayStatusAttribute(): string
    {
        if(
            in_array($this->status,['open','partial'],true)
            && $this->due_date
            && $this->due_date->isBefore(today())
        ) {
            return 'overdue';
        }

        return $this->status;
    }

    public function getStatusLabelAttribute(): string
    {
        return match($this->display_status) {
            'paid'=>'Pago',
            'partial'=>'Parcial',
            'cancelled'=>'Cancelado',
            'overdue'=>'Vencido',
            default=>'Em aberto',
        };
    }

    public function getTypeLabelAttribute(): string
    {
        return $this->type==='payable' ? 'A pagar' : 'A receber';
    }
}
