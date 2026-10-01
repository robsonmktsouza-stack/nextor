<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleItem extends Model {
    public $timestamps=false;

    protected $fillable=[
        'item_type','product_id','service_id','quantity','unit_price','discount','line_total',
        'product_name','product_sku','notes'
    ];

    protected function casts(): array {
        return [
            'quantity'=>'decimal:3',
            'unit_price'=>'decimal:2',
            'discount'=>'decimal:2',
            'line_total'=>'decimal:2',
        ];
    }

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function service(): BelongsTo { return $this->belongsTo(Service::class); }
}
