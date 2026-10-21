<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class Plan extends Model
{
    protected $fillable = [
        'code', 'name', 'description', 'monthly_price',
        'period_months', 'modules', 'active',
    ];

    protected function casts(): array
    {
        return [
            'modules' => 'array',
            'active' => 'boolean',
            'monthly_price' => 'decimal:2',
            'period_months' => 'integer',
        ];
    }

    public static function enabled()
    {
        return self::query()->where('active', true)->orderBy('id');
    }
}
