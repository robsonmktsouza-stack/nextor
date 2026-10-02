<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinancialCategory extends Model
{
    protected $fillable=['name','type','is_active'];

    protected function casts(): array
    {
        return ['is_active'=>'boolean'];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(FinancialEntry::class,'category_id');
    }
}
