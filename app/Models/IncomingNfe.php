<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class IncomingNfe extends Model
{
    protected $table='incoming_nfes';
    protected $fillable=[
        'access_key','nsu','supplier_cnpj','supplier_name','issued_at','total',
        'status','manifestation','manifestation_protocol','manifested_at',
        'item_count','items','summary','xml_path','received_at',
    ];

    public function manifestationAttempts(): HasMany
    {
        return $this->hasMany(IncomingNfeManifestationAttempt::class);
    }

    public function latestManifestationAttempt(): HasOne
    {
        return $this->hasOne(IncomingNfeManifestationAttempt::class)->latestOfMany();
    }

    public function purchase(): HasOne
    {
        return $this->hasOne(Purchase::class,'incoming_nfe_id');
    }

    protected function casts(): array
    {
        return [
            'issued_at'=>'datetime','received_at'=>'datetime','manifested_at'=>'datetime',
            'total'=>'decimal:2',
            'items'=>'array','summary'=>'array',
        ];
    }
}
