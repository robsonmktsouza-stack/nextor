<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class AutomationDelivery extends Model
{
    public $timestamps = false;

    protected $fillable = ['automation_rule_id', 'company_id', 'fingerprint'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function rule()
    {
        return $this->belongsTo(AutomationRule::class, 'automation_rule_id');
    }
}
