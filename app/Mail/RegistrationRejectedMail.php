<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class RegistrationRejectedMail extends Mailable
{
    public function __construct(public User $user, public ?string $reason = null)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'ผลการสมัครใช้งาน - U-Tapao ATC');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.registration-rejected');
    }
}
