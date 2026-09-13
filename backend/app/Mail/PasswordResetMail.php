<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $resetUrl, public string $name = '', public string $brandName = 'Stylish Holidays')
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Reset your password - ' . $this->brandName,
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: '<div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;padding:24px;">'
                . '<h2>Hello ' . e($this->name ?: 'there') . ',</h2>'
                . '<p>Click the button below to set a new password. The link expires in 60 minutes.</p>'
                . '<p><a href="' . e($this->resetUrl) . '" style="display:inline-block;background:#EA580C;color:#fff;padding:12px 28px;border-radius:12px;text-decoration:none;font-weight:bold;">Reset password</a></p>'
                . '<p style="color:#64748b;font-size:13px;">If you did not request this, ignore this message.</p>'
                . '</div>',
        );
    }
}
