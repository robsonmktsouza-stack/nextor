<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class FiscalTaxGroupRevision extends Model
{
    public $timestamps = false;

    protected $fillable = ['fiscal_tax_group_id', 'revision', 'user_id', 'snapshot', 'created_at'];

    protected function casts(): array
    {
        return ['revision'=>'integer', 'snapshot'=>'array', 'created_at'=>'datetime'];
    }
}
