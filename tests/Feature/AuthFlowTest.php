<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Company;
use App\Models\PasswordResetOtp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthFlowTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Auth Flow Co',
            'email' => 'authflow@company.test',
            'address' => 'Damascus',
            'phone' => '+963000000',
            'status' => 'active',
        ]);

        $this->user = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'Auth Flow User',
            'email' => 'authflow@user.test',
            'password_hash' => bcrypt('OldPassword123!'),
            'role' => Role::Employee->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);
    }

    public function test_login_succeeds_and_returns_token(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'authflow@user.test',
            'password' => 'OldPassword123!',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.user.email', 'authflow@user.test');
        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_login_explains_unknown_email(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'nobody@user.test',
            'password' => 'OldPassword123!',
        ])
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'No account is registered with this email.')
            ->assertJsonPath('errors.email.0', 'No account is registered with this email.');
    }

    public function test_login_explains_wrong_password(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'authflow@user.test',
            'password' => 'WrongPassword123!',
        ])
            ->assertStatus(401)
            ->assertJsonPath('message', 'The password is incorrect.')
            ->assertJsonPath('errors.password.0', 'The password is incorrect.');
    }

    public function test_login_explains_missing_fields(): void
    {
        $this->postJson('/api/auth/login', [])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Please enter your email address. Please enter your password.')
            ->assertJsonPath('errors.email.0', 'Please enter your email address.')
            ->assertJsonPath('errors.password.0', 'Please enter your password.');
    }

    public function test_login_explains_invalid_email_format(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'not-an-email',
            'password' => 'anything',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The email address format is invalid. Example: name@company.com');
    }

    public function test_login_explains_inactive_account(): void
    {
        $this->user->update(['status' => 'inactive']);

        $this->postJson('/api/auth/login', [
            'email' => 'authflow@user.test',
            'password' => 'OldPassword123!',
        ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'This account is inactive. Contact your company administrator.');
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $tokenA = $this->postJson('/api/auth/login', [
            'email' => 'authflow@user.test',
            'password' => 'OldPassword123!',
        ])->json('data.token');

        $tokenB = $this->postJson('/api/auth/login', [
            'email' => 'authflow@user.test',
            'password' => 'OldPassword123!',
        ])->json('data.token');

        $this->assertNotEmpty($tokenA);
        $this->assertNotEmpty($tokenB);

        $tokenAId = explode('|', $tokenA, 2)[0];
        $tokenBId = explode('|', $tokenB, 2)[0];

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $tokenAId]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $tokenBId]);

        $logoutResponse = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->postJson('/api/auth/logout');

        $logoutResponse->assertStatus(200);
        $logoutResponse->assertJson([
            'success' => true,
            'message' => 'Logged out successfully.',
        ]);

        // Only the token used to authenticate the logout request is deleted...
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenAId]);
        // ...the other token issued to the same user is untouched.
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $tokenBId]);
    }

    public function test_logout_without_token_is_unauthenticated(): void
    {
        $this->postJson('/api/auth/logout')->assertStatus(401);
    }

    public function test_forgot_password_verify_otp_and_reset_password_flow(): void
    {
        $forgot = $this->postJson('/api/auth/forgot-password', [
            'email' => 'authflow@user.test',
        ]);
        $forgot->assertStatus(200);
        $forgot->assertJsonPath('success', true);

        $otp = PasswordResetOtp::where('email', 'authflow@user.test')->latest()->first();
        $this->assertNotNull($otp);

        $verify = $this->postJson('/api/auth/verify-otp', [
            'email' => 'authflow@user.test',
            'otp' => $otp->otp,
        ]);
        $verify->assertStatus(200);
        $verify->assertJsonPath('success', true);

        $reset = $this->postJson('/api/auth/reset-password', [
            'email' => 'authflow@user.test',
            'password' => 'NewPassword456!',
            'password_confirmation' => 'NewPassword456!',
        ]);
        $reset->assertStatus(200);
        $reset->assertJsonPath('success', true);

        $login = $this->postJson('/api/auth/login', [
            'email' => 'authflow@user.test',
            'password' => 'NewPassword456!',
        ]);
        $login->assertStatus(200);
        $login->assertJsonPath('success', true);
    }

    public function test_reset_password_rejects_weak_password_with_specific_messages(): void
    {
        $this->postJson('/api/auth/forgot-password', ['email' => 'authflow@user.test']);
        $otp = PasswordResetOtp::where('email', 'authflow@user.test')->latest()->first();
        $this->postJson('/api/auth/verify-otp', [
            'email' => 'authflow@user.test',
            'otp' => $otp->otp,
        ]);

        // Lowercase-only, no digits, no symbols, too short.
        $response = $this->postJson('/api/auth/reset-password', [
            'email' => 'authflow@user.test',
            'password' => 'weak',
            'password_confirmation' => 'weak',
        ]);

        $response->assertStatus(422);
        $errors = $response->json('errors.password');
        $this->assertContains('Password must be at least 8 characters.', $errors);
        $this->assertContains('Password must contain both uppercase and lowercase letters.', $errors);
        $this->assertContains('Password must contain at least one number.', $errors);
        $this->assertContains('Password must contain at least one special character.', $errors);
    }

    public function test_complete_first_login_rejects_weak_password_with_specific_messages(): void
    {
        $this->actingAs($this->user);

        $response = $this->postJson('/api/auth/complete-first-login', [
            'password' => 'weak',
            'password_confirmation' => 'weak',
        ]);

        $response->assertStatus(422);
        $errors = $response->json('errors.password');
        $this->assertContains('Password must be at least 8 characters.', $errors);
        $this->assertContains('Password must contain both uppercase and lowercase letters.', $errors);
        $this->assertContains('Password must contain at least one number.', $errors);
        $this->assertContains('Password must contain at least one special character.', $errors);
        $this->assertStringContainsString('special character', $response->json('message'));
    }

    public function test_complete_first_login_rejects_password_without_symbol_with_clear_message(): void
    {
        $this->actingAs($this->user);

        $response = $this->postJson('/api/auth/complete-first-login', [
            'password' => 'Rama1234',
            'password_confirmation' => 'Rama1234',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $response->assertJsonFragment([
            'message' => 'Password must contain at least one special character.',
        ]);
    }

    public function test_complete_first_login_accepts_strong_password(): void
    {
        $this->actingAs($this->user);

        $response = $this->postJson('/api/auth/complete-first-login', [
            'password' => 'Strong@Pass1',
            'password_confirmation' => 'Strong@Pass1',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
    }
}
