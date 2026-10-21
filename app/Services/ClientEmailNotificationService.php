<?php

namespace App\Services;

use App\Mail\LicenseExpiryNotice;
use App\Models\ClientEmailDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

final class ClientEmailNotificationService
{
    public function enabled(): bool
    {
        return (bool) config('client_notifications.email_enabled', false)
            && config('mail.default') === 'smtp'
            && filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL) !== false;
    }

    public function sendPending(int $limit = 50): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        if (!$this->enabled()) return $result;

        $eligible = fn ($q) => $q->where('status', 'pending')
            ->orWhere(fn ($retry) => $retry->where('status', 'retry')
                ->where('next_attempt_at', '<=', now()))
            ->orWhere(fn ($stale) => $stale->where('status', 'processing')
                ->where('claimed_at', '<', now()->subMinutes(15)));

        $ids = ClientEmailDelivery::query()->where($eligible)
            ->orderBy('id')->limit(max(1, min(100, $limit)))->pluck('id');

        foreach ($ids as $id) {
            // Compare-and-set claim prevents simultaneous schedulers sending the same mail.
            $claimed = ClientEmailDelivery::query()->whereKey($id)->where($eligible)
                ->update(['status' => 'processing', 'claimed_at' => now(),
                    'attempts' => DB::raw('attempts + 1')]);
            if (!$claimed) continue;

            $delivery = ClientEmailDelivery::query()->with(['rule', 'company'])->findOrFail($id);
            $company = $delivery->company;
            $rule = $delivery->rule;
            $date = $company?->expires_at?->toDateString();
            $kind = $rule?->kindCode();
            $valid = $company && $rule && $rule->enabled && $rule->notify_client_email
                && $company->license_status === 'active'
                && filter_var($company->email, FILTER_VALIDATE_EMAIL) !== false
                && in_array($kind, ['expiry_soon', 'expired'], true)
                && $date !== null
                && $delivery->fingerprint === ($kind === 'expired' ? 'expired-' : 'expiry-').$date
                && ($kind === 'expired'
                    ? $date < today()->toDateString()
                    : ($date >= today()->toDateString()
                        && $date <= today()->addDays($rule->days)->toDateString()));

            if (!$valid) {
                $delivery->update(['status' => 'skipped', 'claimed_at' => null]);
                $result['skipped']++;
                continue;
            }

            try {
                Mail::to($company->email)->send(new LicenseExpiryNotice(
                    $company->legal_name,
                    $company->expires_at->format('d/m/Y'),
                    $kind === 'expired',
                ));
                $delivery->update(['status' => 'sent', 'sent_at' => now(),
                    'claimed_at' => null, 'next_attempt_at' => null]);
                $company->record('client_email_sent', 'Aviso de licença enviado por e-mail.', [
                    'automation_rule_id' => $rule->id, 'delivery_id' => $delivery->id,
                ]);
                $result['sent']++;
            } catch (\Throwable) {
                // Store no SMTP credentials, address or exception text in the audit.
                $attempts = (int) $delivery->attempts;
                $delivery->update([
                    'status' => $attempts >= 3 ? 'failed' : 'retry',
                    'claimed_at' => null,
                    'next_attempt_at' => $attempts >= 3 ? null : now()->addMinutes(5 * $attempts),
                ]);
                $result['failed']++;
            }
        }
        return $result;
    }
}