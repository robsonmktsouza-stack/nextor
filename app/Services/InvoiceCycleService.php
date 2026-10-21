<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Invoice;
use App\Support\InvoiceMoney;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class InvoiceCycleService
{
    public function __construct(private readonly InvoiceService $invoices) {}

    /**
     * Called inside the company registration transaction. The initial term
     * stays as configured by the operator; the invoice covers ONLY the next
     * contracted cycle, so payment never charges that initial access twice.
     * Plans without a positive configured price do not receive a guessed bill.
     */
    public function prepareForNewCompany(Company $company): ?Invoice
    {
        $plan = $company->planDefinition()->first();
        if (!$this->hasBillablePrice($plan)) {
            return null;
        }

        $start = $company->expires_at->copy()->addDay();
        return $this->invoices->create($company, [
            'description' => 'Licença '.$plan->name,
            'period_start' => $start->toDateString(),
            'period_end' => $this->periodEnd($start, (int) $plan->period_months)->toDateString(),
            'issue_date' => today()->toDateString(),
            'due_date' => $company->expires_at->gte(today())
                ? $company->expires_at->toDateString() : today()->toDateString(),
            'origin' => 'onboarding',
        ]);
    }

    /**
     * No backfill for old accounts or historical cycles. Recurrence begins
     * exclusively for accounts with a prior, issued, not-cancelled invoice.
     * Generated invoices are drafts (no external email, Pix charge or boleto).
     * Lock each company, then reuse InvoiceService overlap protection.
     */
    public function prepareNextDrafts(int $daysAhead = 7): array
    {
        $daysAhead = max(0, min(30, $daysAhead));
        $result = ['created' => 0, 'skipped' => 0];
        Company::query()
            ->where('license_status', 'active')
            ->whereHas('invoices', fn ($q) => $q->where('status', '!=', 'cancelled'))
            ->orderBy('id')
            ->chunkById(100, function ($companies) use ($daysAhead, &$result): void {
                foreach ($companies as $company) {
                    DB::transaction(function () use ($company, $daysAhead, &$result): void {
                        $locked = Company::query()->lockForUpdate()->findOrFail($company->id);
                        if ($locked->license_status !== 'active') {
                            $result['skipped']++;
                            return;
                        }
                        $latest = Invoice::query()->where('company_id', $locked->id)
                            ->where('status', '!=', 'cancelled')
                            ->orderByDesc('period_end')->orderByDesc('id')->first();

                        // Don't leap over a draft or invent several missed cycles.
                        if (!$latest || $latest->status !== 'issued'
                            || $latest->period_end->toDateString() > today()->addDays($daysAhead)->toDateString()
                            || $latest->period_end->toDateString() < today()->subDays($daysAhead)->toDateString()) {
                            $result['skipped']++;
                            return;
                        }
                        $plan = $locked->planDefinition()->first();
                        if (!$this->hasBillablePrice($plan)) {
                            $result['skipped']++;
                            return;
                        }
                        $start = $latest->period_end->copy()->addDay();
                        $this->invoices->create($locked, [
                            'description' => 'Licença '.$plan->name,
                            'period_start' => $start->toDateString(),
                            'period_end' => $this->periodEnd($start, (int) $plan->period_months)->toDateString(),
                            'issue_date' => today()->toDateString(),
                            'due_date' => $start->copy()->subDay()->lt(today())
                                ? today()->toDateString() : $start->copy()->subDay()->toDateString(),
                            'origin' => 'recurring',
                        ]);
                        $result['created']++;
                    });
                }
            });

        return $result;
    }

    private function hasBillablePrice(?\App\Models\Plan $plan): bool
    {
        return $plan && $plan->monthly_price !== null
            && InvoiceMoney::cents((string) $plan->monthly_price) > 0
            && in_array((int) $plan->period_months, [1, 3, 6, 12], true);
    }

    private function periodEnd(Carbon $start, int $months): Carbon
    {
        return $start->copy()->addMonthsNoOverflow($months)->subDay();
    }
}
