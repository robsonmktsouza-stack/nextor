<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookDelivery extends Model
{
    protected $fillable=[
        'event','url','payload','status','attempts','response_code','last_error',
        'delivered_at','next_attempt_at',
    ];

    protected function casts(): array
    {
        return [
            'payload'=>'array',
            'delivered_at'=>'datetime',
            'next_attempt_at'=>'datetime',
        ];
    }
}
