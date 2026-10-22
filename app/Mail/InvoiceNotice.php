<?php
namespace App\Mail;

use App\Models\Invoice;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class InvoiceNotice extends Mailable
{
    public function __construct(public readonly Invoice $invoice) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Lumeron — fatura '.$this->invoice->number);
    }

    public function content(): Content
    {
        return new Content(text: 'emails.invoice-notice');
    }
}
