<?php

namespace App\Fiscal\Models;

use App\Fiscal\Enums\FiscalEnvironment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FiscalCompany extends Model
{
    protected $fillable = [
        'legal_name', 'trade_name', 'cnpj', 'state_registration', 'crt',
        'uf', 'city_ibge', 'street', 'number', 'complement', 'district',
        'city', 'zip_code', 'environment', 'production_enabled', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'environment' => FiscalEnvironment::class,
            'production_enabled' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function certificate(): HasOne
    {
        return $this->hasOne(FiscalCertificate::class);
    }

    public function sequences(): HasMany
    {
        return $this->hasMany(FiscalSequence::class);
    }
}
