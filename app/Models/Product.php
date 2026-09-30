<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Product extends Model {
    protected $fillable=['sku','name','description','category','unit','cost_price','sale_price','stock_quantity','minimum_stock','is_active'];
    protected function casts(): array { return ['cost_price'=>'decimal:2','sale_price'=>'decimal:2','stock_quantity'=>'decimal:3','minimum_stock'=>'decimal:3','is_active'=>'boolean']; }
    public function movements(): HasMany { return $this->hasMany(StockMovement::class); }
}
