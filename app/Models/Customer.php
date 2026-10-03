<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model {
    protected $fillable=[
        'name','trade_name','document','contact_name','is_customer','is_supplier','is_carrier',
        'email','phone','zip_code','state','city','city_ibge_code','address','address_number','address_complement','district',
        'country_code','country_name','foreign_id','final_consumer','ie_indicator','state_registration','substitute_state_registration',
        'municipal_registration','suframa','government_entity','rntrc','carrier_type','driver_license',
        'birth_date','keywords','celebration_date','celebration_note','lgpd_legal_basis','notes'
    ];

    protected function casts(): array {
        return [
            'is_customer'=>'boolean','is_supplier'=>'boolean','is_carrier'=>'boolean',
            'final_consumer'=>'boolean','birth_date'=>'date','celebration_date'=>'date',
        ];
    }

    public function deliveryAddresses(): HasMany {
        return $this->hasMany(CustomerDeliveryAddress::class);
    }
}
