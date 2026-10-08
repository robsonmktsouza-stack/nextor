<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class FiscalTaxRule extends Model
{
    protected $fillable = [
        'name','document_type','origin_uf','destination_uf','crt','product_id',
        'ncm_prefix','cfop','csosn','pis_cst','cofins_cst','priority',
        'valid_from','valid_until','revision','is_active','notes',
    ];

    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'priority' => 'integer',
            'revision' => 'integer',
            'is_active' => 'boolean',
            'valid_from' => 'date',
            'valid_until' => 'date',
        ];
    }
}
