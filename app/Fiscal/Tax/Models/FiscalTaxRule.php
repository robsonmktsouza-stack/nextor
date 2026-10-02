<?php

namespace App\Fiscal\Tax\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalTaxRule extends Model
{
    protected $fillable = [
        'fiscal_tax_group_id',
        'operation_scope',
        'cfop',
        'icms_csosn',
        'pis_cst',
        'pis_rate',
        'pis_base_mode',
        'cofins_cst',
        'cofins_rate',
        'cofins_base_mode',
        'rtc_mode',
        'ibs_cst',
        'ibs_classification',
        'ibs_uf_rate',
        'ibs_mun_rate',
        'cbs_rate',
        'ibs_cbs_base_mode',
        'rule_version',
        'effective_from',
        'effective_to',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'pis_rate' => 'decimal:4',
            'cofins_rate' => 'decimal:4',
            'ibs_uf_rate' => 'decimal:4',
            'ibs_mun_rate' => 'decimal:4',
            'cbs_rate' => 'decimal:4',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(FiscalTaxGroup::class, 'fiscal_tax_group_id');
    }
}
