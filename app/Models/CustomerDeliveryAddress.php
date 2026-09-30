<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerDeliveryAddress extends Model {
    protected $fillable=[
        'name','document','state_registration','zip_code','state','city',
        'address','address_number','address_complement','district','email','phone'
    ];

    public function customer(): BelongsTo {
        return $this->belongsTo(Customer::class);
    }
}
