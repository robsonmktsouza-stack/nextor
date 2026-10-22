<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Support\InvoiceMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class InvoiceService
{
    public function create(Company $company, array $data): Invoice
    {
        return DB::transaction(function () use ($company, $data): Invoice {
            $company = Company::query()->with('planDefinition')->lockForUpdate()->findOrFail($company->id);
            $this->ensureFreePeriod($company, $data['period_start'], $data['period_end']);
            $plan = $company->planDefinition;
            if (!$plan) {
                throw ValidationException::withMessages(['company_id' => 'O cliente não possui um plano válido.']);
            }

            $inputAmount = $data['amount'] ?? null;
            if ($inputAmount === null || $inputAmount === '') {
                if ($plan->monthly_price === null) {
                    throw ValidationException::withMessages(['amount' => 'Informe o valor da fatura.']);
                }
                $cents = InvoiceMoney::cents((string) $plan->monthly_price) * (int) $plan->period_months;
                if ($cents < 1 || $cents > 999999999999) {
                    throw ValidationException::withMessages(['amount' => 'Informe um valor válido para a fatura.']);
                }
                $amount = InvoiceMoney::decimal($cents);
            } else {
                $amount = $this->amount((string) $inputAmount);
            }

            $invoice = Invoice::query()->create([
                'company_id' => $company->id,
                'company_name' => $company->legal_name,
                'company_cnpj' => $company->cnpj,
                'plan_code' => $plan->code,
                'plan_name' => $plan->name,
                'description' => ($data['description'] ?? null) ?: 'Licença '.$plan->name,
                'period_start' => $data['period_start'],
                'period_end' => $data['period_end'],
                'issue_date' => $data['issue_date'],
                'due_date' => $data['due_date'],
                'amount' => $amount,
                'created_by' => auth()->id(),
                'origin' => $data['origin'] ?? 'manual',
            ]);
            $invoice->number = 'FAT-'.str_pad((string) $invoice->id, 6, '0', STR_PAD_LEFT);
            $invoice->save();
            $company->record('invoice_created', 'Fatura '.$invoice->number.' criada.', [
                'invoice_id' => $invoice->id,
            ]);
            return $invoice;
        });
    }

    public function edit(Invoice $invoice, array $data): void
    {
        DB::transaction(function () use ($invoice, $data): void {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $this->assertDraft($invoice);
            $this->ensureFreePeriod($invoice->company, $data['period_start'],
                $data['period_end'], $invoice->id);
            $invoice->update([
                'description' => $data['description'],
                'period_start' => $data['period_start'],
                'period_end' => $data['period_end'],
                'issue_date' => $data['issue_date'],
                'due_date' => $data['due_date'],
                'amount' => $this->amount((string) $data['amount']),
            ]);
            $invoice->company->record('invoice_changed',
                'Fatura '.$invoice->number.' atualizada.', ['invoice_id' => $invoice->id]);
        });
    }

    public function issue(Invoice $invoice): void
    {
        DB::transaction(function () use ($invoice): void {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $this->assertDraft($invoice);
            $invoice->forceFill(['status' => 'issued', 'issued_at' => now()])->save();
            app(InvoiceEmailNotificationService::class)->enqueue($invoice);
            $invoice->company->record('invoice_issued',
                'Fatura '.$invoice->number.' emitida.', ['invoice_id' => $invoice->id]);
        });
    }

    public function recordPayment(Invoice $invoice, array $data): InvoicePayment
    {
        return DB::transaction(function () use ($invoice, $data): InvoicePayment {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status !== 'issued') {
                throw ValidationException::withMessages(['amount' => 'Esta fatura não aceita pagamentos.']);
            }
            $cents = InvoiceMoney::cents((string) $data['amount']);
            if ($cents < 1 || $cents > $invoice->balanceCents()) {
                throw ValidationException::withMessages([
                    'amount' => 'O valor deve ser maior que zero e não ultrapassar o saldo.',
                ]);
            }
            $payment = $invoice->payments()->create([
                'amount' => InvoiceMoney::decimal($cents),
                'paid_on' => $data['paid_on'],
                'method' => $data['method'],
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'recorded_by' => auth()->id(),
            ]);
            $invoice->company->record('invoice_payment',
                'Pagamento registrado na fatura '.$invoice->number.'.', [
                    'invoice_id' => $invoice->id, 'payment_id' => $payment->id,
                    'amount' => $payment->amount,
                ]);

            // A manually confirmed full payment of an automatically prepared
            // cycle is sufficient to apply the covered term. Leave manually
            // created invoices and mismatched/ad-hoc licence terms alone.
            if (in_array($invoice->origin, ['onboarding', 'recurring'], true)
                && $invoice->balanceCents() === 0
                && $invoice->license_applied_at === null) {
                $company = Company::query()->lockForUpdate()->findOrFail($invoice->company_id);
                $termImmediatelyFollows = $company->expires_at !== null
                    && $company->expires_at->copy()->addDay()->toDateString()
                        === $invoice->period_start->toDateString();
                if ($termImmediatelyFollows) {
                    $this->applyPaidPeriod($invoice);
                }
            }
            return $payment;
        });
    }

    public function reversePayment(Invoice $invoice, InvoicePayment $payment, string $reason): void
    {
        DB::transaction(function () use ($invoice, $payment, $reason): void {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $payment = InvoicePayment::query()->where('invoice_id', $invoice->id)
                ->lockForUpdate()->findOrFail($payment->id);
            if ($invoice->status !== 'issued' || $payment->reversed_at !== null
                || $invoice->licenseApplicationActive()) {
                throw ValidationException::withMessages(['payment' => 'Desfaça a renovação aplicada à licença antes de estornar.']);
            }
            $payment->forceFill([
                'reversed_at' => now(),
                'reversal_reason' => $reason,
                'reversed_by' => auth()->id(),
            ])->save();
            $invoice->company->record('invoice_payment_reversed',
                'Pagamento estornado na fatura '.$invoice->number.'.', [
                    'invoice_id' => $invoice->id, 'payment_id' => $payment->id,
                ]);
        });
    }

    /**
     * Explicit commercial activation after verifying the FULL manual settlement.
     * Never reactivate a suspended license or trust an ERP-side payment.
     */
    public function applyPaidPeriod(Invoice $invoice): void
    {
        DB::transaction(function () use ($invoice): void {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status !== 'issued' || $invoice->balanceCents() !== 0
                || $invoice->license_applied_at !== null) {
                throw ValidationException::withMessages([
                    'invoice' => 'A fatura precisa estar quitada e ainda não aplicada à licença.',
                ]);
            }
            $company = Company::query()->lockForUpdate()->findOrFail($invoice->company_id);
            $old = $company->expires_at?->toDateString();
            $target = $invoice->period_end->toDateString();
            if ($old === null || $target <= $old || $target < today()->toDateString()) {
                throw ValidationException::withMessages([
                    'invoice' => 'O período pago não amplia a validade atual desta licença.',
                ]);
            }

            $company->update(['expires_at' => $target]);
            $invoice->forceFill([
                'license_applied_at' => now(),
                'previous_license_expiry' => $old,
                'license_applied_by' => auth()->id(),
            ])->save();
            $company->record('invoice_license_applied',
                'Validade renovada pela fatura '.$invoice->number.'.', [
                    'invoice_id' => $invoice->id,
                    'previous_expiry' => $old,
                    'expires_at' => $target,
                ]);
        });
    }

    public function undoPaidPeriod(Invoice $invoice): void
    {
        DB::transaction(function () use ($invoice): void {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if (!$invoice->licenseApplicationActive()) {
                throw ValidationException::withMessages(['invoice' => 'Esta fatura não possui renovação ativa.']);
            }
            $company = Company::query()->lockForUpdate()->findOrFail($invoice->company_id);
            if ($company->expires_at?->toDateString() !== $invoice->period_end->toDateString()) {
                throw ValidationException::withMessages([
                    'invoice' => 'A licença já foi alterada depois desta renovação. Confira a validade antes de desfazer.',
                ]);
            }
            $old = $invoice->previous_license_expiry?->toDateString();
            $company->update(['expires_at' => $old]);
            $invoice->forceFill(['license_application_reversed_at' => now()])->save();
            $company->record('invoice_license_reversed',
                'Renovação da fatura '.$invoice->number.' desfeita.', [
                    'invoice_id' => $invoice->id,
                    'expires_at' => $old,
                ]);
        });
    }

    public function cancel(Invoice $invoice, string $reason): void
    {
        DB::transaction(function () use ($invoice, $reason): void {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status === 'cancelled') {
                throw ValidationException::withMessages(['reason' => 'Fatura já cancelada.']);
            }
            if ($invoice->licenseApplicationActive()) {
                throw ValidationException::withMessages([
                    'reason' => 'Desfaça a renovação da licença antes de cancelar a fatura.',
                ]);
            }
            if ($invoice->paidCents() > 0) {
                throw ValidationException::withMessages([
                    'reason' => 'Estorne os pagamentos antes de cancelar a fatura.',
                ]);
            }
            $invoice->forceFill([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ])->save();
            $invoice->company->record('invoice_cancelled',
                'Fatura '.$invoice->number.' cancelada.', [
                    'invoice_id' => $invoice->id, 'reason' => $reason,
                ]);
        });
    }

    private function assertDraft(Invoice $invoice): void
    {
        if ($invoice->status !== 'draft') {
            throw ValidationException::withMessages([
                'invoice' => 'Somente faturas em rascunho podem ser editadas.',
            ]);
        }
    }

    private function amount(string $input): string
    {
        try {
            $cents = InvoiceMoney::cents($input);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['amount' => 'Informe um valor monetário válido.']);
        }
        if ($cents < 1) {
            throw ValidationException::withMessages(['amount' => 'Informe um valor maior que zero.']);
        }
        return InvoiceMoney::decimal($cents);
    }

    private function ensureFreePeriod(Company $company, string $start, string $end, ?int $exceptId = null): void
    {
        if ($end < $start) {
            throw ValidationException::withMessages([
                'period_end' => 'A data final precisa ser igual ou posterior à inicial.',
            ]);
        }
        $duplicates = Invoice::query()->where('company_id', $company->id)
            ->where('status', '!=', 'cancelled')
            ->where('period_start', '<=', $end)
            ->where('period_end', '>=', $start);
        if ($exceptId) $duplicates->whereKeyNot($exceptId);
        if ($duplicates->exists()) {
            throw ValidationException::withMessages([
                'period_start' => 'Já existe uma fatura para este cliente neste período.',
            ]);
        }
    }
}
