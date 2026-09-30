<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Sale extends Model {
    protected $fillable=['customer_id','user_id','status','total','notes','completed_at','cancelled_at'];
    protected function casts(): array { return ['total'=>'decimal:2','completed_at'=>'datetime','cancelled_at'=>'datetime']; }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function items(): HasMany { return $this->hasMany(SaleItem::class); }
}
