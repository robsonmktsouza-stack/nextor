<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinancialRecurrence extends Model
{
    protected $fillable=[
        'type','category_id','customer_id','financial_account_id','created_by','description','amount',
        'frequency','interval_count','start_date','next_date','end_date','payment_method','keywords',
        'notes','is_active','last_generated_at',
    ];

    protected function casts(): array
    {
        return [
            'amount'=>'decimal:2','start_date'=>'date','next_date'=>'date','end_date'=>'date',
            'is_active'=>'boolean','last_generated_at'=>'datetime',
        ];
    }

    public function category(): BelongsTo { return $this->belongsTo(FinancialCategory::class,'category_id'); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function account(): BelongsTo { return $this->belongsTo(FinancialAccount::class,'financial_account_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class,'created_by'); }
    public function entries(): HasMany { return $this->hasMany(FinancialEntry::class,'recurrence_id'); }

    public function getFrequencyLabelAttribute(): string
    {
        if($this->interval_count===1) {
            return match($this->frequency){
                'weekly'=>'Toda semana',
                'yearly'=>'Todo ano',
                default=>'Todo mês',
            };
        }

        $unit=match($this->frequency){
            'weekly'=>'semanas',
            'yearly'=>'anos',
            default=>'meses',
        };

        return 'A cada '.$this->interval_count.' '.$unit;
    }
}
