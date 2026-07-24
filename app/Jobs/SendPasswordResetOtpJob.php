<?php

namespace App\Jobs;

use App\Mail\PasswordResetOtpMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendPasswordResetOtpJob implements ShouldQueue
{
    use Dispatchable, Queueable, SerializesModels;

    public function __construct(
        public string $email,
        public string $otp
    ) {}

    public function handle(): void
    {
        Mail::to($this->email)
            ->send(new PasswordResetOtpMail($this->otp));
    }
}
