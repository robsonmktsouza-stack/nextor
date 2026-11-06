<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class FiscalTaxGroup extends Model
{
    protected $attributes = ['revision'=>1];

    protected $fillable = [
        'name','kind','is_active','is_default','revision','cfop_pattern','preset_key','target_crt',
        'nfce_csosn','icms_csosn','icms_cst','pis_cst','cofins_cst',
        'ipi_cst','iss_exigibility','tax_config','notes',
        'catalog_version','catalog_status','catalog_fingerprint','catalog_metadata',
        'fiscal_reviewed_at','fiscal_reviewed_by',
    ];

    public function revisions(): HasMany
    {
        return $this->hasMany(FiscalTaxGroupRevision::class)->orderByDesc('revision');
    }

    protected function casts(): array
    {
        return [
            'catalog_metadata'=>'array', 'fiscal_reviewed_at'=>'datetime',
            'fiscal_reviewed_by'=>'integer',
            'is_active'=>'boolean','is_default'=>'boolean',
            'revision'=>'integer','tax_config'=>'array',
        ];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }
}
