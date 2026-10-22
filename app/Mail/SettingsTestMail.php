<?php
namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class SettingsTestMail extends Mailable
{
    public function __construct(public readonly string $recipientName) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Lumeron — teste de e-mail');
    }

    public function content(): Content
    {
        return new Content(text: 'emails.settings-test');
    }
}
