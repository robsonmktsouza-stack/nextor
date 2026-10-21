<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class AutomationRule extends Model
{
    protected $fillable = ['enabled', 'days'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'days' => 'integer',
            'last_run_at' => 'datetime',
        ];
    }
}
