<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class NewRegistrationMail extends Mailable
{
    public function __construct(public User $applicant)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'มีผู้สมัครใช้งานใหม่รออนุมัติ - U-Tapao ATC');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.new-registration',
            with: ['url' => route('filament.admin.resources.users.index')],
        );
    }
}
