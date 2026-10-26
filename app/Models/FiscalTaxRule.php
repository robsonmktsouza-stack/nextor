<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class FiscalTaxRule extends Model
{
    protected $fillable = [
        'name','document_type','origin_uf','destination_uf','crt','product_id',
        'ncm_prefix','cfop','csosn','pis_cst','cofins_cst','priority',
        'valid_from','valid_until','revision','is_active','notes',
        'input_cfop','cest','origin','operation_type','fiscal_operation','purpose','recipient_profile','tax_regime',
        'final_consumer','icms_cst','fiscal_benefit_code','tax_parameters','legal_source','legal_reference','layout_version',
        'consulted_at','application_conditions',
        'operation_nature_id','fiscal_tax_group_id','origin_municipality','destination_municipality',
        'recipient_icms_indicator','catalog_version','catalog_status','fiscal_reviewed_at','fiscal_reviewed_by',
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
            'operation_nature_id' => 'integer', 'fiscal_tax_group_id' => 'integer',
            'fiscal_reviewed_at' => 'datetime', 'fiscal_reviewed_by' => 'integer',
        ];
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(FiscalTaxRuleRevision::class)->orderByDesc('revision');
    }

    public function operationNature(): BelongsTo
    {
        return $this->belongsTo(OperationNature::class);
    }

    public function fiscalTaxGroup(): BelongsTo
    {
        return $this->belongsTo(FiscalTaxGroup::class);
    }

    /** Immutable evidence copied to the document, independently of later edits. */
    public function trace(): array
    {
        $snapshot = $this->only($this->getFillable());
        foreach (['valid_from', 'valid_until', 'consulted_at'] as $field) {
            $snapshot[$field] = $this->{$field}?->format('Y-m-d');
        }
        $extended = ['operation_nature_id','fiscal_tax_group_id','origin_municipality','destination_municipality',
            'recipient_icms_indicator','catalog_version','catalog_status','fiscal_reviewed_at','fiscal_reviewed_by'];
        // Adding nullable catalog columns must not invalidate an existing reserved NF-e's legacy hash.
        if (!array_filter(array_intersect_key($snapshot, array_flip($extended)), static fn ($value) => $value !== null && $value !== '')) {
            foreach ($extended as $field) unset($snapshot[$field]);
        } else {
            $snapshot['fiscal_reviewed_at'] = $this->fiscal_reviewed_at?->toIso8601String();
        }
        return ['id' => $this->id, 'revision' => $this->revision, 'legal_source' => $this->legal_source,
            'legal_reference' => $this->legal_reference, 'layout_version' => $this->layout_version,
            'consulted_at' => $snapshot['consulted_at'], 'snapshot' => $snapshot,
            'hash' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))];
    }
}
