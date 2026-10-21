<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Compute a display-only subscription warning from an HMAC-authenticated
 * licence. The server-side enforcement always uses the original status.
 */
final class LicenseReminder
{
    public static function fromState(array $state): ?array
    {
        if (($state['status'] ?? null) !== 'active') return null;
        $threshold = $state['notice_days'] ?? null;
        $date = $state['expires_at'] ?? null;
        if (!is_int($threshold) || $threshold < 0 || $threshold > 365
            || !is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }
        try {
            $days = (int) today()->diffInDays(Carbon::parse($date)->startOfDay(), false);
        } catch (\Throwable) {
            return null;
        }
        if ($days < 0 || $days > $threshold) return null;

        return [
            'days' => $days,
            'date' => Carbon::parse($date)->format('d/m/Y'),
            'text' => $days === 0 ? 'Sua licença vence hoje'
                : ($days === 1 ? 'Sua licença vence amanhã'
                    : 'Sua licença vence em '.$days.' dias'),
        ];
    }
}
