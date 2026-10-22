<?php
namespace App\Services;

use App\Mail\InvoiceNotice;
use App\Models\ControlSetting;
use App\Models\Invoice;
use App\Models\InvoiceEmailDelivery;
use Illuminate\Support\Facades\DB;

final class InvoiceEmailNotificationService
{
    public function __construct(private readonly OutboundMailSettings $mail) {}

    public function automaticEnabled(): bool
    {
        return ($setting = ControlSetting::stored()) !== null
            && $setting->invoice_email_enabled && $this->mail->enabled();
    }

    /** The unique invoice_id prevents duplicate notifications for the same invoice. */
    public function enqueue(Invoice $invoice): bool
    {
        if (!$this->automaticEnabled() || $invoice->status !== 'issued'
            || filter_var($invoice->company?->email, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        return InvoiceEmailDelivery::query()->firstOrCreate(
            ['invoice_id' => $invoice->id], ['status' => 'pending']
        )->wasRecentlyCreated;
    }

    public function sendPending(int $limit = 50): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        if (!$this->automaticEnabled()) return $result;

        $eligible = fn ($query) => $query->where('status', 'pending')
            ->orWhere(fn ($retry) => $retry->where('status', 'retry')
                ->where('next_attempt_at', '<=', now()))
            ->orWhere(fn ($stale) => $stale->where('status', 'processing')
                ->where('claimed_at', '<', now()->subMinutes(15)));

        $ids = InvoiceEmailDelivery::query()->where($eligible)->orderBy('id')
            ->limit(max(1, min($limit, 100)))->pluck('id');

        foreach ($ids as $id) {
            $claimed = InvoiceEmailDelivery::query()->whereKey($id)->where($eligible)
                ->update(['status' => 'processing', 'claimed_at' => now(),
                    'attempts' => DB::raw('attempts + 1')]);
            if (!$claimed) continue;

            $delivery = InvoiceEmailDelivery::query()->with('invoice.company')->findOrFail($id);
            $invoice = $delivery->invoice;
            if (!$invoice || $invoice->status !== 'issued'
                || filter_var($invoice->company?->email, FILTER_VALIDATE_EMAIL) === false) {
                $delivery->update(['status' => 'skipped', 'claimed_at' => null]);
                $result['skipped']++;
                continue;
            }

            try {
                $this->mail->send($invoice->company->email, new InvoiceNotice($invoice));
                $delivery->update(['status' => 'sent', 'sent_at' => now(),
                    'claimed_at' => null, 'next_attempt_at' => null]);
                try {
                    $invoice->company->record('invoice_emailed',
                        'Fatura '.$invoice->number.' enviada por e-mail.',
                        ['invoice_id' => $invoice->id]);
                } catch (\Throwable) {
                    // Keep authoritative outbox status even if optional history failed.
                }
                $result['sent']++;
            } catch (\Throwable) {
                $attempts = (int) $delivery->attempts;
                $delivery->update([
                    'status' => $attempts >= 3 ? 'failed' : 'retry',
                    'claimed_at' => null,
                    'next_attempt_at' => $attempts >= 3 ? null
                        : now()->addMinutes(5 * $attempts),
                ]);
                $result['failed']++;
            }
        }
        return $result;
    }
}
