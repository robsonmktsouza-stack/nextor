<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class IncomingNfe extends Model
{
    protected $fillable=[
        'access_key','nsu','supplier_cnpj','supplier_name','issued_at','total',
        'status','manifestation','item_count','items','summary','xml_path','received_at',
    ];

    protected function casts(): array
    {
        return [
            'issued_at'=>'datetime','received_at'=>'datetime','total'=>'decimal:2',
            'items'=>'array','summary'=>'array',
        ];
    }
}
