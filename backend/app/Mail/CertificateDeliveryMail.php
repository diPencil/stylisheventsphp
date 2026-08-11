<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CertificateDeliveryMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public array $certificate)
    {
    }

    public function envelope(): Envelope
    {
        $eventName = $this->certificate['eventName'] ?? 'Event';
        $subject = ($this->certificate['locale'] ?? 'en') === 'ar'
            ? "شهادتك - {$eventName}"
            : "Your Certificate – {$eventName}";

        return new Envelope(
            from: new Address((string) config('mail.from.address'), (string) config('mail.from.name', 'Stylish Events')),
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.certificate-delivery',
            with: ['certificate' => $this->certificate],
        );
    }
}
