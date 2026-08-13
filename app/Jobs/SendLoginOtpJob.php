<?php

namespace App\Jobs;

use App\Mail\LoginOtpMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendLoginOtpJob implements ShouldQueue
{
    use Dispatchable, Queueable, SerializesModels;

    public function __construct(
        public string $email,
        public string $otp
    ) {}

    public function handle(): void
    {
        Mail::to($this->email)
            ->send(new LoginOtpMail($this->otp));
    }
}
