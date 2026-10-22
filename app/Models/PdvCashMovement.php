<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PdvCashMovement extends Model
{
    protected $fillable=[
        'cash_session_id','user_id','type','amount','reason','sale_id','sale_return_id','approved_by',
    ];

    protected function casts(): array
    {
        return [
            'amount'=>'decimal:2',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(PdvCashSession::class,'cash_session_id');
    }

    public function sale(): BelongsTo { return $this->belongsTo(Sale::class); }
    public function saleReturn(): BelongsTo { return $this->belongsTo(SaleReturn::class,'sale_return_id'); }

    public function approver(): BelongsTo { return $this->belongsTo(User::class,'approved_by'); }
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
