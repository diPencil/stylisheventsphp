<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PlatformTestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $brandName = 'Stylish Holidays')
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Test email from ' . $this->brandName,
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: '<div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;padding:24px;">'
                . '<h2>Outgoing mail is working</h2>'
                . '<p>This is a test message sent from <strong>' . e($this->brandName) . '</strong> event platform email settings.</p>'
                . '<p style="color:#64748b;font-size:13px;">If you received this, the SMTP mailbox is configured correctly.</p>'
                . '</div>',
        );
    }
}
