<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmployeeWelcomeMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $fullName,
        public string $email,
        public string $temporaryPassword,
        public ?string $loginUrl = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Welcome to Khibrat - Your Employee Account',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.employee-welcome',
            with: [
                'fullName' => $this->fullName,
                'email' => $this->email,
                'temporaryPassword' => $this->temporaryPassword,
                'loginUrl' => $this->loginUrl ?? config('app.url'),
            ],
        );
    }
}
