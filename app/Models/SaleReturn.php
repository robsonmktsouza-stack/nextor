<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaleReturn extends Model
{
    protected $fillable=[
        'sale_id','user_id','return_date','status','total','notes','completed_at','cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'return_date'=>'date',
            'total'=>'decimal:2',
            'completed_at'=>'datetime',
            'cancelled_at'=>'datetime',
        ];
    }

    public function sale(): BelongsTo { return $this->belongsTo(Sale::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function items(): HasMany { return $this->hasMany(SaleReturnItem::class); }
}
