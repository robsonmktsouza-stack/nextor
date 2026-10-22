<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class InvoiceEmailDelivery extends Model
{
    protected $fillable = [
        'invoice_id', 'status', 'attempts', 'claimed_at',
        'next_attempt_at', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer', 'claimed_at' => 'datetime',
            'next_attempt_at' => 'datetime', 'sent_at' => 'datetime',
        ];
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
