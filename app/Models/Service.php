<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    protected $fillable = [
        'name',
        'sale_price',
        'keywords',
        'notes',
        'service_list_item',
        'cnae',
        'municipal_tax_code',
        'national_tax_code',
        'nbs',
        'tax_group',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'sale_price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }
}
