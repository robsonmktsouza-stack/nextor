<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A reference snapshot; never a company's approved tax configuration. */
final class FiscalCatalogRelease extends Model
{
    protected $fillable = ['version', 'schema_version', 'sha256', 'consulted_at', 'sources', 'coverage', 'entry_count', 'installed_at'];

    protected function casts(): array
    {
        return ['schema_version' => 'integer', 'entry_count' => 'integer', 'consulted_at' => 'date',
            'installed_at' => 'datetime', 'sources' => 'array', 'coverage' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Versões fiscais instaladas são imutáveis. Importe uma nova versão.'));
        static::deleting(fn () => throw new \LogicException('O histórico do catálogo fiscal deve ser preservado.'));
    }

    public function entries(): HasMany
    {
        return $this->hasMany(FiscalCatalogEntry::class, 'release_id');
    }
}
