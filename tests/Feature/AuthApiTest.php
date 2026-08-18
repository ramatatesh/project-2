<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_token_and_user_data_for_active_user(): void
    {
        $company = new Company();
        $company->id = '11111111-1111-1111-1111-111111111111';
        $company->name = 'Khibrat';
        $company->address = 'HQ';
        $company->save();

        User::create([
            'id' => '22222222-2222-2222-2222-222222222222',
            'company_id' => $company->id,
            'full_name' => 'Test User',
            'email' => 'test@example.com',
            'password_hash' => Hash::make('password123'),
            'role' => 'general_manager',
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'test@example.com')
            ->assertJsonPath('data.company.name', 'Khibrat')
            ->assertJsonPath('data.token', fn ($token) => filled($token));
    }

    public function test_forgot_password_stores_an_otp_for_existing_user(): void
    {
        $company = new Company();
        $company->id = '33333333-3333-3333-3333-333333333333';
        $company->name = 'Khibrat';
        $company->address = 'HQ';
        $company->save();

        User::create([
            'id' => '44444444-4444-4444-4444-444444444444',
            'company_id' => $company->id,
            'full_name' => 'Reset User',
            'email' => 'reset@example.com',
            'password_hash' => Hash::make('password123'),
            'role' => 'general_manager',
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/auth/forgot-password', [
            'email' => 'reset@example.com',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        // Current flow is OTP-based (password_reset_otps), not the classic
        // password_reset_tokens link-token table.
        $this->assertDatabaseHas('password_reset_otps', [
            'email' => 'reset@example.com',
            'verified' => false,
        ]);
    }

    public function test_reset_password_updates_password_hash_and_deletes_the_otp(): void
    {
        $company = new Company();
        $company->id = '55555555-5555-5555-5555-555555555555';
        $company->name = 'Khibrat';
        $company->address = 'HQ';
        $company->save();

        $user = User::create([
            'id' => '66666666-6666-6666-6666-666666666666',
            'company_id' => $company->id,
            'full_name' => 'Reset User',
            'email' => 'reset2@example.com',
            'password_hash' => Hash::make('old-password'),
            'role' => 'general_manager',
            'status' => 'active',
        ]);

        // resetPassword() only accepts an OTP that has already passed /verify-otp
        // (verified = true) and has not expired yet.
        \App\Models\PasswordResetOtp::create([
            'email' => $user->email,
            'otp' => '1234',
            'expires_at' => now()->addMinutes(10),
            'verified' => true,
        ]);

        $response = $this->postJson('/api/auth/reset-password', [
            'email' => $user->email,
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $user->refresh();
        $this->assertTrue(Hash::check('NewPassword123!', $user->password_hash));
        $this->assertDatabaseMissing('password_reset_otps', [
            'email' => $user->email,
        ]);
    }
}
