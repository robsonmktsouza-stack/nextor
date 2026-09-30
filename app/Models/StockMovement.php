<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class StockMovement extends Model {
    public $timestamps=false;
    protected $fillable=['product_id','user_id','sale_id','type','quantity_delta','previous_quantity','new_quantity','reason'];
    protected function casts(): array { return ['quantity_delta'=>'decimal:3','previous_quantity'=>'decimal:3','new_quantity'=>'decimal:3','created_at'=>'datetime']; }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
