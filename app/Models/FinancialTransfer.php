<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialTransfer extends Model
{
    protected $fillable=['from_account_id','to_account_id','user_id','amount','transfer_date','description','notes','cancelled_at'];

    protected function casts(): array
    {
        return ['amount'=>'decimal:2','transfer_date'=>'date','cancelled_at'=>'datetime'];
    }

    public function fromAccount(): BelongsTo { return $this->belongsTo(FinancialAccount::class,'from_account_id'); }
    public function toAccount(): BelongsTo { return $this->belongsTo(FinancialAccount::class,'to_account_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
