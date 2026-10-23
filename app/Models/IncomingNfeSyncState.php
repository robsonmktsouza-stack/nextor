<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class IncomingNfeSyncState extends Model
{
    protected $fillable=[
        'cnpj','environment','last_nsu','max_nsu','auto_sync',
        'next_check_at','last_checked_at','last_status','last_error',
    ];

    protected function casts(): array
    {
        return [
            'auto_sync'=>'boolean','next_check_at'=>'datetime','last_checked_at'=>'datetime',
        ];
    }
}
