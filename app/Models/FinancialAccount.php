<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinancialAccount extends Model
{
    protected $fillable=['name','type','opening_balance','is_active'];

    protected function casts(): array
    {
        return [
            'opening_balance'=>'decimal:2',
            'is_active'=>'boolean',
        ];
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(FinancialSettlement::class);
    }
}
