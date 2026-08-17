<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LocaleSwitchTest extends TestCase
{
    use RefreshDatabase;

    public function test_translations_endpoint_returns_arabic_dictionary_without_auth(): void
    {
        $response = $this->getJson('/api/translations?lang=ar');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.locale', 'ar')
            ->assertJsonPath('data.dir', 'rtl');

        $this->assertSame(
            'لا يوجد حساب مسجّل بهذا البريد الإلكتروني.',
            $response->json('data.translations')['No account is registered with this email.']
        );
    }

    public function test_translations_endpoint_returns_english_keys_for_en(): void
    {
        $response = $this->getJson('/api/translations?lang=en');

        $response->assertOk()
            ->assertJsonPath('data.locale', 'en')
            ->assertJsonPath('data.dir', 'ltr');

        $this->assertSame(
            'No account is registered with this email.',
            $response->json('data.translations')['No account is registered with this email.']
        );
    }

    public function test_login_error_switches_language_via_header_without_reload(): void
    {
        $english = $this->withHeader('X-Locale', 'en')
            ->postJson('/api/auth/login', [
                'email' => 'missing@test.com',
                'password' => 'WrongPass1!',
            ]);

        $english->assertStatus(401)
            ->assertJsonPath('message', 'No account is registered with this email.')
            ->assertHeader('Content-Language', 'en');

        $arabic = $this->withHeader('X-Locale', 'ar')
            ->postJson('/api/auth/login', [
                'email' => 'missing@test.com',
                'password' => 'WrongPass1!',
            ]);

        $arabic->assertStatus(401)
            ->assertJsonPath('message', 'لا يوجد حساب مسجّل بهذا البريد الإلكتروني.')
            ->assertHeader('Content-Language', 'ar');
    }

    public function test_accept_language_header_selects_arabic(): void
    {
        $this->withHeader('Accept-Language', 'ar-SY,ar;q=0.9,en;q=0.8')
            ->postJson('/api/auth/login', [
                'email' => 'x',
                'password' => 'short',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'صيغة البريد الإلكتروني غير صحيحة. مثال: name@company.com');
    }

    public function test_validation_errors_are_translated_to_arabic(): void
    {
        $company = Company::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Locale Co',
            'email' => 'locale@company.test',
            'address' => 'Address',
            'phone' => '0911111111',
            'status' => 'active',
        ]);

        $user = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $company->id,
            'full_name' => 'Locale User',
            'email' => 'locale@user.test',
            'password_hash' => bcrypt('OldPassword123!'),
            'role' => Role::Employee->value,
            'status' => 'active',
            'is_first_login' => true,
        ]);

        $this->actingAs($user)
            ->withHeader('X-Locale', 'ar')
            ->postJson('/api/auth/complete-first-login', [
                'password' => 'Rama1234',
                'password_confirmation' => 'Rama1234',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'يجب أن تحتوي كلمة المرور على رمز خاص واحد على الأقل.');
    }
}
