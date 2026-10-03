<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OperationNature extends Model
{
    protected $fillable=[
        'name','operation_type','purpose','cfop_internal','cfop_interstate',
        'cfop_inbound_internal','cfop_inbound_interstate','cfop_foreign',
        'override_product_cfop','final_consumer_default','presence_default','move_stock',
        'generate_finance','allow_referenced_document','require_transport','require_invoice',
        'require_duplicates','additional_info','tax_authority_info','is_active',
    ];

    protected function casts(): array
    {
        return [
            'override_product_cfop'=>'boolean',
            'final_consumer_default'=>'boolean',
            'move_stock'=>'boolean',
            'generate_finance'=>'boolean',
            'allow_referenced_document'=>'boolean',
            'require_transport'=>'boolean',
            'require_invoice'=>'boolean',
            'require_duplicates'=>'boolean',
            'is_active'=>'boolean',
        ];
    }

    public function nfeDrafts(): HasMany
    {
        return $this->hasMany(NfeDraft::class);
    }
}
