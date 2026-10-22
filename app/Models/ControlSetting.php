<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ControlSetting extends Model
{
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $fillable = [
        'smtp_host', 'smtp_port', 'smtp_security', 'smtp_username',
        'smtp_password', 'smtp_from_address', 'smtp_from_name',
        'email_enabled', 'invoice_email_enabled', 'recurring_invoices_enabled',
        'invoice_days_ahead', 'automations_at', 'invoices_at',
        'emails_start_at', 'emails_end_at',
    ];
    protected $hidden = ['smtp_username', 'smtp_password'];

    protected function casts(): array
    {
        return [
            'smtp_username' => 'encrypted', 'smtp_password' => 'encrypted',
            'email_enabled' => 'boolean', 'invoice_email_enabled' => 'boolean',
            'recurring_invoices_enabled' => 'boolean', 'smtp_port' => 'integer',
            'invoice_days_ahead' => 'integer',
        ];
    }

    /** Missing record keeps existing projects' legacy defaults unchanged. */
    public static function saved(): ?self
    {
        return static::query()->find(1);
    }

    /** The scheduler is loaded even before migrations and when the DB is offline. */
    public static function safeSaved(): ?self
    {
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('control_settings')) return null;
            return self::saved();
        } catch (\Throwable) {
            return null;
        }
    }

    public static function schedule(string $field, string $default): string
    {
        $stored = self::safeSaved();
        $value = (string) ($stored?->{$field} ?? $default);
        return preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $value) ? $value : $default;
    }

    public static function recurringEnabled(): bool
    {
        return self::saved()?->recurring_invoices_enabled ?? true;
    }

    public static function invoiceDays(): int
    {
        return max(0, min(30, (int) (self::saved()?->invoice_days_ahead ?? 7)));
    }

    public function smtpReady(): bool
    {
        return is_string($this->smtp_host) && $this->smtp_host !== ''
            && in_array($this->smtp_security, ['tls', 'ssl'], true)
            && $this->smtp_port >= 1 && $this->smtp_port <= 65535
            && filter_var($this->smtp_from_address, FILTER_VALIDATE_EMAIL) !== false
            && filled($this->smtp_username) && filled($this->smtp_password);
    }
}
