<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class FiscalTaxRuleRevision extends Model
{
    public $timestamps = false;
    protected $fillable = ['fiscal_tax_rule_id', 'revision', 'changed_by', 'snapshot', 'snapshot_hash', 'created_at'];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'snapshot' => 'array', 'created_at' => 'datetime'];
    }
}
