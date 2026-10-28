<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class FiscalOperationNatureRevision extends Model
{
    public $timestamps = false;

    protected $fillable = ['operation_nature_id', 'revision', 'user_id', 'snapshot', 'created_at'];

    protected function casts(): array
    {
        return ['revision'=>'integer', 'snapshot'=>'array', 'created_at'=>'datetime'];
    }
}
