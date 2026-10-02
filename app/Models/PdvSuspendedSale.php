<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PdvSuspendedSale extends Model
{
    protected $fillable=[
        'user_id','label','payload','total','item_count','suspended_at','resumed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload'=>'array',
            'total'=>'decimal:2',
            'suspended_at'=>'datetime',
            'resumed_at'=>'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
