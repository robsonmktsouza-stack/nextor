<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NfeDraft extends Model
{
    protected $fillable=[
        'operation_nature_id','customer_id','user_id','status','operation_type','destination',
        'presence','purpose','final_consumer','issue_date','issue_time','exit_date','exit_time',
        'expected_delivery_date','government_purchase','advance_payment','different_delivery',
        'discount','surcharge','payment_type','payment_condition','payment_other_description',
        'card_brand','card_acquirer_document','card_authorization_code',
        'series','document_number','environment','emitter_snapshot','recipient_snapshot',
        'delivery_snapshot','transport_data','invoice_data','duplicates','payments','references',
        'custom_fields','totals','additional_info','tax_authority_info','validated_at',
    ];

    protected function casts(): array
    {
        return [
            'final_consumer'=>'boolean',
            'government_purchase'=>'boolean',
            'advance_payment'=>'boolean',
            'different_delivery'=>'boolean',
            'discount'=>'decimal:2',
            'surcharge'=>'decimal:2',
            'issue_date'=>'date',
            'exit_date'=>'date',
            'expected_delivery_date'=>'date',
            'emitter_snapshot'=>'array',
            'recipient_snapshot'=>'array',
            'delivery_snapshot'=>'array',
            'transport_data'=>'array',
            'invoice_data'=>'array',
            'duplicates'=>'array',
            'payments'=>'array',
            'references'=>'array',
            'custom_fields'=>'array',
            'totals'=>'array',
            'validated_at'=>'datetime',
        ];
    }

    public function operationNature(): BelongsTo
    {
        return $this->belongsTo(OperationNature::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(NfeDraftItem::class)->orderBy('item_number');
    }
}
