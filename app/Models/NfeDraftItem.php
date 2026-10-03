<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NfeDraftItem extends Model
{
    protected $fillable=[
        'nfe_draft_id','product_id','item_number','product_name','product_sku','quantity','dimension_quantity',
        'unit_price','freight','insurance','other_expenses','discount','line_total','cfop',
        'origin','ean_gtin','unit','tax_unit','ncm','cest','ipi_exception','fiscal_benefit_code',
        'purchase_order','purchase_order_item','notes','tax_data','special_data',
    ];

    protected function casts(): array
    {
        return [
            'quantity'=>'decimal:4',
            'dimension_quantity'=>'decimal:4',
            'unit_price'=>'decimal:4',
            'freight'=>'decimal:2',
            'insurance'=>'decimal:2',
            'other_expenses'=>'decimal:2',
            'discount'=>'decimal:2',
            'line_total'=>'decimal:2',
            'tax_data'=>'array',
            'special_data'=>'array',
        ];
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(NfeDraft::class,'nfe_draft_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
