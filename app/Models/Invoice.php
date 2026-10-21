<?php

namespace App\Models;

use App\Support\InvoiceMoney;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Invoice extends Model
{
    protected $fillable = [
        'company_id', 'company_name', 'company_cnpj', 'plan_code', 'plan_name',
        'description', 'period_start', 'period_end', 'issue_date', 'due_date',
        'amount', 'created_by', 'origin',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date', 'period_end' => 'date',
            'issue_date' => 'date', 'due_date' => 'date',
            'issued_at' => 'datetime', 'cancelled_at' => 'datetime',
            'license_applied_at' => 'datetime', 'license_application_reversed_at' => 'datetime',
            'previous_license_expiry' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }

    public function payments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class)->orderBy('id');
    }

    public function paidCents(): int
    {
        return $this->payments()->whereNull('reversed_at')->pluck('amount')
            ->sum(fn ($amount) => InvoiceMoney::cents((string) $amount));
    }

    public function totalCents(): int { return InvoiceMoney::cents((string) $this->amount); }

    public function balanceCents(): int { return max(0, $this->totalCents() - $this->paidCents()); }

    public function effectiveStatus(): string
    {
        if ($this->status === 'cancelled') return 'cancelled';
        if ($this->status === 'draft') return 'draft';
        if ($this->balanceCents() === 0) return 'paid';
        if ($this->due_date->toDateString() < today()->toDateString()) return 'overdue';
        return $this->paidCents() > 0 ? 'partial' : 'open';
    }

    public function licenseApplicationActive(): bool
    {
        return $this->license_applied_at !== null
            && $this->license_application_reversed_at === null;
    }

    public function statusLabel(): string
    {
        return match ($this->effectiveStatus()) {
            'draft' => 'Rascunho',
            'paid' => 'Paga',
            'overdue' => 'Vencida',
            'partial' => 'Parcial',
            'cancelled' => 'Cancelada',
            default => 'Em aberto',
        };
    }
}
