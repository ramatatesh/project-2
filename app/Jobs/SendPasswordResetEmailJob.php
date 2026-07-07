<?php

namespace App\Jobs;

use App\Mail\PasswordResetMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendPasswordResetEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $email, public string $token)
    {
    }

    public function handle(): void
    {
        try {
            Mail::to($this->email)->send(new PasswordResetMail($this->email, $this->token));

            Log::info('Password reset email sent', [
                'email' => $this->email,
            ]);
        } catch (\Throwable $th) {
            Log::error('Password reset email failed', [
                'email' => $this->email,
                'error' => $th->getMessage(),
            ]);
        }
    }
}
