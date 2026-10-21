<?php

namespace App\Services;

use App\Models\AutomationRule;
use App\Models\Company;
use Illuminate\Support\Facades\DB;

final class AutomationRunner
{
    /**
     * Internal-only reminders; never emails clients, bills, renews or
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

            if ($rule->code === 'expiry_soon') {
                $query->where('license_status', 'active')
                    ->whereBetween('expires_at', [
                        $today->toDateString(),
                        $today->copy()->addDays($rule->days)->toDateString(),
                    ]);
            } elseif ($rule->code === 'expired') {
                $query->where('license_status', 'active')
                    ->whereDate('expires_at', '<', $today->toDateString());
            } elseif ($rule->code === 'offline') {
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
        if ($rule->code === 'expiry_soon') {
            return [
                'expiry-'.$company->expires_at->toDateString(),
                'Automação: licença próxima do vencimento em '.$company->expires_at->format('d/m/Y').'. Verifique a renovação.',
            ];
        }
        if ($rule->code === 'expired') {
            return [
                'expired-'.$company->expires_at->toDateString(),
                'Automação: licença vencida em '.$company->expires_at->format('d/m/Y').'. O estado de acesso já é calculado pelo prazo.',
            ];
        }
        $last = $company->last_seen_at ?? $company->created_at;
        return [
            'offline-'.$last->format('Y-m-d-His'),
            'Automação: instalação sem comunicação com a central desde '.$last->format('d/m/Y H:i').'.',
        ];
    }
}
