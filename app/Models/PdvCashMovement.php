<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PdvCashMovement extends Model
{
    protected $fillable=[
        'cash_session_id','user_id','type','amount','reason',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
