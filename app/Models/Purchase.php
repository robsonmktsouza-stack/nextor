<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Purchase extends Model
{
    protected $fillable=[
        'supplier_id','incoming_nfe_id','created_by','status','operation_date','due_date',
        'document_number','keyword','subtotal','freight','discount','other_expenses','total','generate_payable',
        'notes','received_at','cancelled_at',
    ];
    protected function casts(): array
    {
        return [
            'operation_date'=>'date','due_date'=>'date',
            'subtotal'=>'decimal:2','freight'=>'decimal:2','discount'=>'decimal:2',
            'total'=>'decimal:2','other_expenses'=>'decimal:2','generate_payable'=>'boolean',
            'received_at'=>'datetime','cancelled_at'=>'datetime',
        ];
    }
    public function supplier(): BelongsTo {return $this->belongsTo(Customer::class,'supplier_id');}
    public function invoice(): BelongsTo {return $this->belongsTo(IncomingNfe::class,'incoming_nfe_id');}
    public function creator(): BelongsTo {return $this->belongsTo(User::class,'created_by');}
    public function items(): HasMany {return $this->hasMany(PurchaseItem::class);}
    public function payable(): HasOne {return $this->hasOne(FinancialEntry::class,'purchase_id');}
    public function movements(): HasMany {return $this->hasMany(StockMovement::class,'purchase_id');}
}
