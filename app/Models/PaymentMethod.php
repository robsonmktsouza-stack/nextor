<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentMethod extends Model
{
    protected $fillable=[
        'code','name','kind','financial_account_id','fee_percent','fee_fixed','settlement_days',
        'is_active','pdv_enabled','sort_order',
    ];

    protected function casts(): array
    {
        return [
            'fee_percent'=>'decimal:4',
            'fee_fixed'=>'decimal:2',
            'settlement_days'=>'integer',
            'is_active'=>'boolean',
            'pdv_enabled'=>'boolean',
            'sort_order'=>'integer',
        ];
    }

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    public static function options(bool $pdvOnly=false): array
    {
        return static::query()
            ->where('is_active',true)
            ->when($pdvOnly,fn($q)=>$q->where('pdv_enabled',true))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('name','code')
            ->all();
    }
}
