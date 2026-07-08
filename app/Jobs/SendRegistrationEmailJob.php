<?php

namespace App\Jobs;

use App\Mail\RegistrationMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendRegistrationEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $email, public string $temporaryPassword)
    {
    }

    public function handle(): void
    {
        try {
            Mail::to($this->email)->send(new RegistrationMail($this->email, $this->temporaryPassword));

            Log::info('Registration email sent', [
                'email' => $this->email,
            ]);
        } catch (\Throwable $th) {
            Log::error('Registration email failed', [
                'email' => $this->email,
                'error' => $th->getMessage(),
            ]);
        }
    }
}
