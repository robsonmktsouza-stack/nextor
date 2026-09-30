<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class SaleItem extends Model {
    public $timestamps=false;
    protected $fillable=['product_id','quantity','unit_price','line_total','product_name','product_sku'];
    protected function casts(): array { return ['quantity'=>'decimal:3','unit_price'=>'decimal:2','line_total'=>'decimal:2']; }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
}
