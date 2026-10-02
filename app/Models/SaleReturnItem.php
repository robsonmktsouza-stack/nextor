<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleReturnItem extends Model
{
    public $timestamps=false;

    protected $fillable=[
        'sale_return_id','sale_item_id','product_id','service_id','item_type','item_name','item_code',
        'quantity','unit_price','line_total','reason',
    ];

    protected function casts(): array
    {
        return [
            'quantity'=>'decimal:3',
            'unit_price'=>'decimal:2',
            'line_total'=>'decimal:2',
        ];
    }

    public function saleReturn(): BelongsTo { return $this->belongsTo(SaleReturn::class); }
    public function saleItem(): BelongsTo { return $this->belongsTo(SaleItem::class); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function service(): BelongsTo { return $this->belongsTo(Service::class); }
}
