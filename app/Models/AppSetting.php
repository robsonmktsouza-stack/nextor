<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class AppSetting extends Model
{
    protected $fillable=['group','key','value','is_secret'];

    protected function casts(): array
    {
        return ['is_secret'=>'boolean'];
    }

    public static function value(string $group,string $key,mixed $default=null): mixed
    {
        $row=static::rawGroup($group)[$key] ?? null;
        if(!$row || $row['value']===null || $row['value']==='') return $default;

        try {
            $raw=$row['is_secret'] ? Crypt::decryptString($row['value']) : $row['value'];
        } catch (\Throwable) {
            return $default;
        }

        return static::decode($raw,$default);
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

        Cache::forget('nextor.settings.raw.'.$group);
    }

    public static function reserveInteger(string $group,string $key,int $default=1): int
    {
        return DB::transaction(function() use($group,$key,$default) {
            $row=static::query()
                ->where('group',$group)
                ->where('key',$key)
                ->lockForUpdate()
                ->first();

            $current=$default;

            if($row && $row->value!==null && $row->value!=='') {
                try {
                    $raw=$row->is_secret ? Crypt::decryptString($row->value) : $row->value;
                    $decoded=static::decode($raw,$default);
                    $current=max(1,(int)$decoded);
                } catch (\Throwable) {
                    $current=$default;
                }
            }

            static::put($group,$key,$current+1,false);

            return $current;
        },3);
    }

    public static function dateFormat(): string
    {
        $format=(string)static::value('system','date_format','d/m/Y');
        return in_array($format,['d/m/Y','Y-m-d'],true) ? $format : 'd/m/Y';
    }

    public static function tablePerPage(Request $request): int
    {
        $allowed=[10,25,50,100];
        $requested=$request->integer('per_page');

        if(in_array($requested,$allowed,true)) {
            $request->session()->put('table_per_page',$requested);
        }

        $default=(int)static::value('system','rows_per_page',25);
        if(!in_array($default,$allowed,true)) $default=25;

        $perPage=(int)$request->session()->get('table_per_page',$default);
        return in_array($perPage,$allowed,true) ? $perPage : $default;
    }

    public static function groupValues(string $group,array $defaults=[]): array
    {
        $values=$defaults;

        foreach(static::rawGroup($group) as $key=>$row) {
            try {
                $raw=$row['is_secret'] && $row['value'] ? Crypt::decryptString($row['value']) : $row['value'];
                $values[$key]=static::decode($raw,null);
            } catch (\Throwable) {
                $values[$key]=null;
            }
        }

        return $values;
    }

    private static function rawGroup(string $group): array
    {
        try {
            return Cache::remember('nextor.settings.raw.'.$group,3600,function() use($group) {
                return static::query()
                    ->where('group',$group)
                    ->get(['key','value','is_secret'])
                    ->mapWithKeys(fn(self $row)=>[
                        $row->key=>[
                            'value'=>$row->value,
                            'is_secret'=>(bool)$row->is_secret,
                        ],
                    ])
                    ->all();
            });
        } catch (\Throwable) {
            return [];
        }
    }

    private static function decode(mixed $raw,mixed $default=null): mixed
    {
        if(!is_string($raw)) return $raw ?? $default;

        if(str_starts_with($raw,'json:')) {
            return json_decode(substr($raw,5),true) ?? $default;
        }

        return $raw;
    }
}
