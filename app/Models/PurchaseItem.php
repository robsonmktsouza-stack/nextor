<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseItem extends Model
{
    public $timestamps=false;
    protected $fillable=['product_id','product_name','product_sku','unit','quantity','unit_cost','line_total','discount','other_costs','external_costs','ipi','icms_st'];
    protected function casts(): array
    {
        return ['quantity'=>'decimal:3','unit_cost'=>'decimal:2','line_total'=>'decimal:2','discount'=>'decimal:2','other_costs'=>'decimal:2','external_costs'=>'decimal:2','ipi'=>'decimal:2','icms_st'=>'decimal:2'];
    }
    public function purchase(): BelongsTo {return $this->belongsTo(Purchase::class);}
    public function product(): BelongsTo {return $this->belongsTo(Product::class);}
}
