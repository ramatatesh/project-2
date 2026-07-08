<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RegistrationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public string $email, public string $temporaryPassword)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Welcome to Khibrat - Your Account Details',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.registration',
            with: [
                'email' => $this->email,
                'temporaryPassword' => $this->temporaryPassword,
            ],
        );
    }
}
