<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VerificationCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $code, public string $name = '', public string $brandName = 'Stylish Holidays')
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Verify your email - ' . $this->brandName,
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: '<div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;padding:24px;">'
                . '<h2>Hello ' . e($this->name ?: 'there') . ',</h2>'
                . '<p>Use this code to verify your email address:</p>'
                . '<p style="font-size:32px;font-weight:bold;letter-spacing:8px;">' . e($this->code) . '</p>'
                . '<p style="color:#64748b;font-size:13px;">The code expires in 30 minutes. If you did not create this account, ignore this message.</p>'
                . '</div>',
        );
    }
}
