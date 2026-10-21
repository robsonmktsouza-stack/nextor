<?php
namespace App\Mail;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class LicenseExpiryNotice extends Mailable
{
    public function __construct(
        public readonly string $companyName,
        public readonly string $expiresDate,
        public readonly bool $expired,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->expired
            ? 'Lumeron: licença vencida em '.$this->expiresDate
            : 'Lumeron: sua licença vence em '.$this->expiresDate);
    }

    public function content(): Content
    {
        return new Content(text: 'emails.license-expiry');
    }
}