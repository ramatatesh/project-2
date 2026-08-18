<?php

namespace Tests\Feature;

use App\Jobs\SendPasswordResetOtpJob;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ForgotPasswordQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_dispatches_queue_job(): void
    {
        Queue::fake();

        $company = Company::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Khibrat',
            'address' => 'HQ',
        ]);

        User::create([
            'company_id' => $company->id,
            'full_name' => 'Test User',
            'email' => 'hr@khibrat.com',
            'password_hash' => bcrypt('secret123'),
            'role' => 'general_manager',
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/auth/forgot-password', [
            'email' => 'hr@khibrat.com',
        ]);

        $response->assertStatus(200);
        Queue::assertPushed(SendPasswordResetOtpJob::class, function (SendPasswordResetOtpJob $job) {
            return $job->email === 'hr@khibrat.com';
        });
    }
}
