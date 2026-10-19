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

    /**
     * Card payments created by the initial migration had kind=card even
     * though their codes identify debit/credit. Preserve old sales and
     * snapshots without guessing the type of an ambiguous custom card.
     */
    public static function resolveFiscalKind(?string $kind, ?string $code): string
    {
        $kind=strtolower(trim((string)$kind));
        if($kind!=='card') return $kind;
        $code=strtolower(trim((string)$code));
        return match($code){
            'credit_card','credit-card','cartao_credito','cartao-credito'=>'credit_card',
            'debit_card','debit-card','cartao_debito','cartao-debito'=>'debit_card',
            default=>'card',
        };
    }

    public function fiscalKind(): string
    {
        return self::resolveFiscalKind($this->kind,$this->code);
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
