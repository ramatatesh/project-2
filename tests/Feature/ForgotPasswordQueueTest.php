<?php

namespace Tests\Feature;

use App\Jobs\SendPasswordResetEmailJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ForgotPasswordQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_dispatches_queue_job(): void
    {
        Queue::fake();

        User::create([
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
        Queue::assertPushed(SendPasswordResetEmailJob::class, function (SendPasswordResetEmailJob $job) {
            return $job->email === 'hr@khibrat.com';
        });
    }
}
