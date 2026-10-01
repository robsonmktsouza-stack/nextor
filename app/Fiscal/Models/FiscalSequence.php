<?php

namespace App\Fiscal\Models;

use App\Fiscal\Enums\FiscalEnvironment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalSequence extends Model
{
    protected $fillable = [
        'fiscal_company_id', 'model', 'series', 'environment',
        'next_number', 'last_reserved_number',
    ];

    protected function casts(): array
    {
        return [
            'environment' => FiscalEnvironment::class,
            'series' => 'integer',
            'next_number' => 'integer',
            'last_reserved_number' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(FiscalCompany::class, 'fiscal_company_id');
    }
}
