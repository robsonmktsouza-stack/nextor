<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class Nfe55Inutilization extends Model
{
    protected $table = 'nfe55_inutilizations';

    protected $fillable = [
        'issuer_document', 'environment', 'year', 'series', 'first_number', 'last_number',
        'reason', 'status', 'protocol', 'response_path', 'xml_path', 'error_message',
        'requested_by', 'attempted_at', 'processed_at',
    ];

    protected function casts(): array
    {
        return ['attempted_at' => 'datetime', 'processed_at' => 'datetime'];
    }
}
