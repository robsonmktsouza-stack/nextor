<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LicenseEvent extends Model
{
    public $timestamps = false;
    protected $fillable = ['user_id', 'event_type', 'description', 'metadata'];
    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'datetime'];
    }
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
