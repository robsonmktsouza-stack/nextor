<?php

namespace App\Fiscal\Tax\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FiscalTaxGroup extends Model
{
    protected $fillable = [
        'code',
        'name',
        'crt',
        'model',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function rules(): HasMany
    {
        return $this->hasMany(FiscalTaxRule::class);
    }
}
