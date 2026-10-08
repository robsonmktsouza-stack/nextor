<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class FiscalFcpRule extends Model
{
    protected $fillable=[
        'uf','ncm_prefix','rate','apply_to_own_fcp','is_active',
        'valid_from','valid_until','notes',
    ];
    protected function casts(): array
    {
        return [
            'rate'=>'decimal:4',
            'apply_to_own_fcp'=>'boolean',
            'is_active'=>'boolean',
            'valid_from'=>'date',
            'valid_until'=>'date',
        ];
    }
}
