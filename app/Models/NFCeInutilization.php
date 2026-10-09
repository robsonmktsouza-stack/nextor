<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class NFCeInutilization extends Model
{
    protected $table='nfce_inutilizations';

    protected $fillable=[
        'environment','issuer_document','year','series','first_number','last_number','reason',
        'status','protocol','response_path','error_message','requested_by','processed_at',
    ];

    protected function casts(): array
    {
        return ['processed_at'=>'datetime'];
    }
}
