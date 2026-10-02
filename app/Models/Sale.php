<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model {
    protected $fillable=[
        'customer_id','consumer_document','consumer_name','user_id','operation_type','source','operation_date','quote_expires_at','final_consumer','keyword',
        'status','subtotal','discount_total','total','notes','completed_at','cancelled_at'
    ];

    protected function casts(): array {
        return [
            'operation_date'=>'date',
            'quote_expires_at'=>'date',
            'final_consumer'=>'boolean',
            'subtotal'=>'decimal:2',
            'discount_total'=>'decimal:2',
            'total'=>'decimal:2',
            'completed_at'=>'datetime',
            'cancelled_at'=>'datetime',
        ];
    }

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function items(): HasMany { return $this->hasMany(SaleItem::class); }
    public function payments(): HasMany { return $this->hasMany(SalePayment::class)->orderBy('installment'); }
    public function financialEntries(): HasMany { return $this->hasMany(FinancialEntry::class); }
    public function returns(): HasMany { return $this->hasMany(SaleReturn::class); }
}
