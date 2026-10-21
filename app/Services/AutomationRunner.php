<?php

namespace App\Services;

use App\Models\AutomationRule;
use App\Models\Company;
use Illuminate\Support\Facades\DB;

final class AutomationRunner
{
    /**
     * Internal history and optional email outbox; never bills, renews or
     * suspends anyone. The expiry status already derives from expires_at.
     *
     * Delivery rows are uniquely indexed so repeated scheduler runs won't
     * produce duplicated history events for the same license condition.
     */
    public function run(): array
    {
        $result = ['rules' => 0, 'new_events' => 0];
        foreach (AutomationRule::query()->where('enabled', true)->orderBy('id')->get() as $rule) {
            $result['rules']++;
            $today = today();
            $query = Company::query()->orderBy('id');

            if ($rule->kindCode() === 'expiry_soon') {
                $query->where('license_status', 'active')
                    ->whereBetween('expires_at', [
                        $today->toDateString(),
                        $today->copy()->addDays($rule->days)->toDateString(),
                    ]);
            } elseif ($rule->kindCode() === 'expired') {
                $query->where('license_status', 'active')
                    ->whereDate('expires_at', '<', $today->toDateString());
            } elseif ($rule->kindCode() === 'offline') {
                $cutoff = $today->copy()->subDays($rule->days)->startOfDay();
                $query->where(function ($q) use ($cutoff): void {
                    $q->where('last_seen_at', '<', $cutoff)
                        ->orWhere(function ($inner) use ($cutoff): void {
                            $inner->whereNull('last_seen_at')->where('created_at', '<', $cutoff);
                        });
                });
            } else {
                continue;
            }

            $query->chunkById(100, function ($companies) use ($rule, &$result): void {
                foreach ($companies as $company) {
                    [$fingerprint, $description] = $this->eventFor($rule, $company);
                    DB::transaction(function () use ($rule, $company, $fingerprint, $description, &$result): void {
                        $inserted = DB::table('automation_deliveries')->insertOrIgnore([
                            'automation_rule_id' => $rule->id,
                            'company_id' => $company->id,
                            'fingerprint' => $fingerprint,
                            'created_at' => now(),
                        ]);
                        if ($inserted > 0) {
                            // Add a pending customer notice only for NEW internal
                            // events; enabling email never resends older notices.
                            if ($rule->notify_client_email
                                && in_array($rule->kindCode(), ['expiry_soon', 'expired'], true)
                                && filter_var($company->email, FILTER_VALIDATE_EMAIL) !== false) {
                                \App\Models\ClientEmailDelivery::query()->firstOrCreate([
                                    'automation_rule_id' => $rule->id,
                                    'company_id' => $company->id,
                                    'fingerprint' => $fingerprint,
                                ], ['status' => 'pending']);
                            }
                            $company->record('automation_'.$rule->code, $description, [
                                'automation_rule_id' => $rule->id,
                                'fingerprint' => $fingerprint,
                            ]);
                            $result['new_events']++;
                        }
                    });
                }
            });
            $rule->forceFill(['last_run_at' => now()])->save();
        }
        return $result;
    }

    private function eventFor(AutomationRule $rule, Company $company): array
    {
        if ($rule->kindCode() === 'expiry_soon') {
            return [
                'expiry-'.$company->expires_at->toDateString(),
                'Licença próxima do vencimento: '.$company->expires_at->format('d/m/Y').'.',
            ];
        }
        if ($rule->kindCode() === 'expired') {
            return [
                'expired-'.$company->expires_at->toDateString(),
                'Licença vencida em '.$company->expires_at->format('d/m/Y').'.',
            ];
        }
        $last = $company->last_seen_at ?? $company->created_at;
        return [
            'offline-'.$last->format('Y-m-d-His'),
            'Sem comunicação desde '.$last->format('d/m/Y H:i').'.',
        ];
    }
}
