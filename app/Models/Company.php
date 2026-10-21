<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

final class Company extends Model
{
    protected $fillable = [
        'instance_id', 'legal_name', 'trade_name', 'cnpj', 'contact_name', 'email',
        'phone', 'subdomain', 'installation_url', 'admin_login', 'plan',
        'license_status', 'expires_at', 'modules', 'license_secret',
    ];

    protected $hidden = ['license_secret'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'date',
            'modules' => 'array',
            'license_secret' => 'encrypted',
            'last_seen_at' => 'datetime',
        ];
    }

    public function planDefinition(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan', 'code');
    }

    /** The plan is the sole source of modules; legacy snapshots are ignored. */
    public function entitledModules(): array
    {
        return $this->planDefinition?->modules ?? [];
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(LicenseEvent::class);
    }

    public function currentStatus(): string
    {
        if ($this->license_status === 'suspended') {
            return 'suspended';
        }
        if ($this->expires_at !== null && $this->expires_at->startOfDay()->lessThan(Carbon::today())) {
            return 'expired';
        }
        return 'active';
    }

    public function record(string $event, string $message, array $metadata = []): void
    {
        $this->events()->create([
            'user_id' => auth()->id(),
            'event_type' => $event,
            'description' => $message,
            'metadata' => $metadata ?: null,
        ]);
    }
}
