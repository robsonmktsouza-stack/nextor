<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class PdvCashRegister extends Model
{
    protected $fillable=['code','name','is_active'];
    protected function casts(): array { return ['is_active'=>'boolean']; }
    public function sessions(): HasMany { return $this->hasMany(PdvCashSession::class,'register_id'); }
}
