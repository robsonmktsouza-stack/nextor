<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ManagedProvisioningJob extends Model
{
    protected $fillable = ['company_id', 'status', 'attempts',
        'started_at', 'completed_at', 'failure_code'];

    protected function casts(): array
    {
        return ['started_at'=>'datetime', 'completed_at'=>'datetime',
            'attempts'=>'integer'];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
