<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class RegistrationApprovedMail extends Mailable
{
    public function __construct(public User $user, public string $role)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'บัญชีของคุณได้รับการอนุมัติแล้ว - U-Tapao ATC');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.registration-approved',
            with: ['url' => route('filament.admin.auth.login')],
        );
    }
}
