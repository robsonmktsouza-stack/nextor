<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class FiscalTaxRule extends Model
{
    protected $fillable = [
        'name','document_type','origin_uf','destination_uf','crt','product_id',
        'ncm_prefix','cfop','csosn','pis_cst','cofins_cst','priority',
        'valid_from','valid_until','revision','is_active','notes',
        'input_cfop','cest','origin','operation_type','fiscal_operation','purpose','recipient_profile','tax_regime',
        'final_consumer','icms_cst','fiscal_benefit_code','tax_parameters','legal_source','legal_reference','layout_version',
        'consulted_at','application_conditions',
    ];

    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'priority' => 'integer',
            'revision' => 'integer',
            'is_active' => 'boolean',
            'valid_from' => 'date',
            'valid_until' => 'date',
            'consulted_at' => 'date',
            'tax_parameters' => 'array',
            'final_consumer' => 'boolean',
        ];
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(FiscalTaxRuleRevision::class)->orderByDesc('revision');
    }

    /** Immutable evidence copied to the document, independently of later edits. */
    public function trace(): array
    {
        $snapshot = $this->only($this->getFillable());
        foreach (['valid_from', 'valid_until', 'consulted_at'] as $field) {
            $snapshot[$field] = $this->{$field}?->format('Y-m-d');
        }
        return ['id' => $this->id, 'revision' => $this->revision, 'legal_source' => $this->legal_source,
            'legal_reference' => $this->legal_reference, 'layout_version' => $this->layout_version,
            'consulted_at' => $snapshot['consulted_at'], 'snapshot' => $snapshot,
            'hash' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))];
    }
}
