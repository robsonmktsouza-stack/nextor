<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialReceipt extends Model
{
    protected $fillable=[
        'customer_id','created_by','issuer_mode','recipient_name','recipient_document',
        'amount','receipt_date','reference','copies','attachment_path','attachment_name',
    ];

    protected function casts(): array
    {
        return ['amount'=>'decimal:2','receipt_date'=>'date','copies'=>'integer'];
    }

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class,'created_by'); }
}
