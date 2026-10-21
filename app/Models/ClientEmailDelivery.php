<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

final class ClientEmailDelivery extends Model
{
    protected $fillable = ['automation_rule_id', 'company_id', 'fingerprint', 'status', 'attempts',
        'next_attempt_at', 'claimed_at', 'sent_at'];

    protected function casts(): array
    {
        return ['next_attempt_at'=>'datetime', 'claimed_at'=>'datetime', 'sent_at'=>'datetime', 'attempts'=>'integer'];
    }

    public function company() { return $this->belongsTo(Company::class); }
    public function rule() { return $this->belongsTo(AutomationRule::class, 'automation_rule_id'); }
}