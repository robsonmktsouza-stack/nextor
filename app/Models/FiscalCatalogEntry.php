<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Official reference or explicit template. No automatic tax determination. */
final class FiscalCatalogEntry extends Model
{
    protected $fillable = ['release_id', 'kind', 'code', 'description', 'valid_from', 'valid_until',
        'uf', 'regime', 'status', 'source', 'conditions', 'parameters', 'entry_key'];

    protected function casts(): array
    {
        return ['valid_from' => 'date', 'valid_until' => 'date', 'source' => 'array',
            'conditions' => 'array', 'parameters' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Referências fiscais são imutáveis por versão. Personalize uma regra da instalação.'));
        static::deleting(fn () => throw new \LogicException('O histórico das referências fiscais deve ser preservado.'));
    }

    public function release(): BelongsTo
    {
        return $this->belongsTo(FiscalCatalogRelease::class, 'release_id');
    }

    public function trace(): array
    {
        return ['release' => $this->release->version, 'release_sha256' => $this->release->sha256,
            'entry_key' => $this->entry_key, 'kind' => $this->kind, 'code' => $this->code,
            'source' => $this->source, 'valid_from' => $this->valid_from?->format('Y-m-d'),
            'valid_until' => $this->valid_until?->format('Y-m-d'), 'uf' => $this->uf,
            'regime' => $this->regime, 'conditions' => $this->conditions, 'status' => $this->status];
    }
}
