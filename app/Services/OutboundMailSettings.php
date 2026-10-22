<?php
namespace App\Services;

use App\Models\ControlSetting;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;

final class OutboundMailSettings
{
    public function settings(): ?ControlSetting
    {
        return ControlSetting::saved();
    }

    public function configured(): bool
    {
        return $this->settings()?->smtpReady() ?? false;
    }

    public function enabled(): bool
    {
        $record = $this->settings();
        if ($record) return $record->email_enabled && $record->smtpReady();

        // Compatibility for installations that have not saved settings yet.
        return (bool) config('client_notifications.email_enabled', false)
            && config('mail.default') === 'smtp'
            && filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL) !== false;
    }

    public function send(string $to, Mailable $message, bool $allowTest = false): void
    {
        $record = $this->settings();
        if ($record) {
            if (!$record->smtpReady() || (!$allowTest && !$record->email_enabled)) {
                throw new \RuntimeException('Envio de e-mail indisponível.');
            }
            // Isolated mailer: never mutate .env or the application's default.
            // Laravel resolves it only when actually sending.
            config()->set('mail.mailers.lumeron_smtp', [
                'transport' => 'smtp',
                'scheme' => $record->smtp_security === 'ssl' ? 'smtps' : 'smtp',
                'host' => $record->smtp_host,
                'port' => $record->smtp_port,
                'username' => $record->smtp_username,
                'password' => $record->smtp_password,
                'timeout' => 10,
            ]);
            $message->from($record->smtp_from_address, $record->smtp_from_name);
            Mail::mailer('lumeron_smtp')->to($to)->send($message);
            return;
        }
        // Existing tests and legacy configured SMTP keep working until migrated.
        if (!$this->enabled()) throw new \RuntimeException('Envio de e-mail indisponível.');
        Mail::to($to)->send($message);
    }
}
