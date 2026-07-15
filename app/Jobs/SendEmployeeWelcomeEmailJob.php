<?php

namespace App\Jobs;

use App\Mail\EmployeeWelcomeMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendEmployeeWelcomeEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $fullName,
        public string $email,
        public string $temporaryPassword,
        public ?string $loginUrl = null,
    ) {
    }

    public function handle(): void
    {
        try {
            Mail::to($this->email)->send(
                new EmployeeWelcomeMail($this->fullName, $this->email, $this->temporaryPassword, $this->loginUrl)
            );

            Log::info('Employee welcome email sent', ['email' => $this->email]);
        } catch (\Throwable $th) {
            Log::error('Employee welcome email failed', [
                'email' => $this->email,
                'error' => $th->getMessage(),
            ]);
        }
    }
}
