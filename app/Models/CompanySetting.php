<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanySetting extends Model
{
    protected $fillable=[
        'document','legal_name','trade_name','state_registration','municipal_registration','cnae_main',
        'phone','email','zip_code','state','city','city_ibge_code','address','address_number',
        'address_complement','district','tax_regime','crt','simple_rate','main_activity',
        'logo_path','print_header','print_footer','show_currency_prefix','timezone',
        'certificate_path','certificate_password','certificate_expires_at',
    ];

    protected function casts(): array
    {
        return [
            'simple_rate'=>'decimal:4',
            'show_currency_prefix'=>'boolean',
            'certificate_password'=>'encrypted',
            'certificate_expires_at'=>'datetime',
        ];
    }

    public static function current(): self
    {
        return static::query()->firstOrCreate([],[
            'timezone'=>'America/Sao_Paulo',
            'show_currency_prefix'=>true,
        ]);
    }
}
