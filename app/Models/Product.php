<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model {
    protected $fillable=[
        'sku','name','description','category','keywords','usage_type','unit',
        'cost_price','sale_price','stock_quantity','minimum_stock','control_stock','is_active',
        'origin','ean_gtin','net_weight','gross_weight','ncm','ipi_exception','cest',
        'fiscal_benefit_code','different_tax_unit','tax_unit','ignore_taxes_mode','nfe_notes','tax_group','fiscal_tax_group_id','tax_defaults',
        'image_path','integration_reference','integration_sku'
    ];

    protected function casts(): array {
        return [
            'cost_price'=>'decimal:2','sale_price'=>'decimal:2',
            'stock_quantity'=>'decimal:3','minimum_stock'=>'decimal:3',
            'net_weight'=>'decimal:3','gross_weight'=>'decimal:3',
            'control_stock'=>'boolean','different_tax_unit'=>'boolean','is_active'=>'boolean',
            'tax_defaults'=>'array',
        ];
    }

    public function movements(): HasMany {
        return $this->hasMany(StockMovement::class);
    }
}
