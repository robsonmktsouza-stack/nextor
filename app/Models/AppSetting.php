<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class AppSetting extends Model
{
    protected $fillable=['group','key','value','is_secret'];

    protected function casts(): array
    {
        return ['is_secret'=>'boolean'];
    }

    public static function value(string $group,string $key,mixed $default=null): mixed
    {
        $row=static::query()->where('group',$group)->where('key',$key)->first();
        if(!$row || $row->value===null || $row->value==='') return $default;

        $raw=$row->is_secret ? Crypt::decryptString($row->value) : $row->value;

        if(str_starts_with($raw,'json:')) {
            return json_decode(substr($raw,5),true) ?? $default;
        }

        return $raw;
    }

    public static function put(string $group,string $key,mixed $value,bool $secret=false): void
    {
        if(is_array($value) || is_bool($value) || is_int($value) || is_float($value)) {
            $value='json:'.json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        } elseif($value!==null) {
            $value=(string)$value;
        }

        if($secret && $value!==null && $value!=='') {
            $value=Crypt::encryptString($value);
        }

        static::query()->updateOrCreate(
            ['group'=>$group,'key'=>$key],
            ['value'=>$value,'is_secret'=>$secret]
        );
    }

    public static function groupValues(string $group,array $defaults=[]): array
    {
        $values=$defaults;

        foreach(static::query()->where('group',$group)->get() as $row) {
            try {
                $raw=$row->is_secret && $row->value ? Crypt::decryptString($row->value) : $row->value;
                $values[$row->key]=is_string($raw) && str_starts_with($raw,'json:')
                    ? (json_decode(substr($raw,5),true) ?? null)
                    : $raw;
            } catch (\Throwable) {
                $values[$row->key]=null;
            }
        }

        return $values;
    }
}
