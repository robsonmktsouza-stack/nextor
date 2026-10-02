<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialBankTransaction extends Model
{
    protected $fillable=[
        'financial_bank_import_id','financial_entry_id','financial_settlement_id','sequence',
        'external_id','transaction_date','amount','description','transaction_type','reconciled_at',
    ];

    protected function casts(): array
    {
        return ['amount'=>'decimal:2','transaction_date'=>'date','reconciled_at'=>'datetime'];
    }

    public function bankImport(): BelongsTo { return $this->belongsTo(FinancialBankImport::class,'financial_bank_import_id'); }
    public function entry(): BelongsTo { return $this->belongsTo(FinancialEntry::class,'financial_entry_id'); }
    public function settlement(): BelongsTo { return $this->belongsTo(FinancialSettlement::class,'financial_settlement_id'); }
}
