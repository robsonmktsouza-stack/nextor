<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class IncomingNfeDocument extends Model
{
    protected $fillable=[
        'cnpj_dest','nsu','access_key','issuer_cnpj','issuer_name',
        'document_number','series','issued_at','total','document_status',
        'xml_path','received_at',
    ];

    protected function casts(): array
    {
        return ['issued_at'=>'datetime','received_at'=>'datetime','total'=>'decimal:2'];
    }
}
