<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinancialBankImport extends Model
{
    protected $fillable=[
        'financial_account_id','created_by','original_name','format','file_sha256',
        'period_start','period_end','transactions_count','status',
    ];

    protected function casts(): array
    {
        return ['period_start'=>'date','period_end'=>'date','transactions_count'=>'integer'];
    }

    public function account(): BelongsTo { return $this->belongsTo(FinancialAccount::class,'financial_account_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class,'created_by'); }
    public function transactions(): HasMany { return $this->hasMany(FinancialBankTransaction::class); }
}
