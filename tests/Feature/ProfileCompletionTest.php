<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProfileCompletionTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $employeeUser;

    private Employee $employee;

    private User $generalManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Profile Co',
            'email' => 'profile@company.test',
            'address' => 'Damascus',
            'phone' => '+963000000',
            'status' => 'active',
        ]);

        $department = Department::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'name' => 'Engineering',
            'is_active' => true,
        ]);

        $this->employeeUser = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'Profile Employee',
            'email' => 'employee@profile.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::Employee->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $this->employee = Employee::create([
            'id' => Str::uuid()->toString(),
            'user_id' => $this->employeeUser->id,
            'company_id' => $this->company->id,
            'department_id' => $department->id,
            'employee_code' => 'EMP-PROFILE-1',
            'job_title' => 'Developer',
            'base_salary' => 1000,
            'hire_date' => '2022-01-01',
            'is_active' => true,
        ]);

        $this->generalManager = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'GM',
            'email' => 'gm@profile.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::GeneralManager->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);
    }

    public function test_new_user_has_profile_completed_false_by_default(): void
    {
        $this->assertFalse($this->employeeUser->fresh()->profile_completed);
    }

    public function test_login_response_includes_profile_completed(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'employee@profile.test',
            'password' => 'password',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.user.profile_completed', false);
    }

    public function test_employee_can_complete_profile_with_documents(): void
    {
        Storage::fake('public');
        $this->actingAs($this->employeeUser);

        $response = $this->postJson('/api/profile/complete', [
            'profile_image' => UploadedFile::fake()->image('profile.jpg'),
            'identity_image' => UploadedFile::fake()->image('id.jpg'),
            'university_certificate' => UploadedFile::fake()->create('certificate.pdf', 100, 'application/pdf'),
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.profile_completed', true);
        $this->assertNotNull($response->json('data.documents.profile_image_url'));
        $this->assertNotNull($response->json('data.documents.identity_image_url'));
        $this->assertNotNull($response->json('data.documents.university_certificate_url'));

        $this->assertTrue($this->employeeUser->fresh()->profile_completed);

        $document = $this->employee->document()->first();
        $this->assertNotNull($document);
        Storage::disk('public')->assertExists($document->profile_image_path);
        Storage::disk('public')->assertExists($document->identity_image_path);
        Storage::disk('public')->assertExists($document->university_certificate_path);
    }

    public function test_profile_complete_without_university_certificate_is_allowed(): void
    {
        Storage::fake('public');
        $this->actingAs($this->employeeUser);

        $response = $this->postJson('/api/profile/complete', [
            'profile_image' => UploadedFile::fake()->image('profile.jpg'),
            'identity_image' => UploadedFile::fake()->image('id.jpg'),
        ]);

        $response->assertStatus(200);
        $this->assertNull($response->json('data.documents.university_certificate_url'));
    }

    public function test_profile_complete_requires_profile_and_identity_images(): void
    {
        $this->actingAs($this->employeeUser);

        $response = $this->postJson('/api/profile/complete', []);

        $response->assertStatus(422);
    }

    public function test_general_manager_without_employee_record_cannot_complete_profile(): void
    {
        Storage::fake('public');
        $this->actingAs($this->generalManager);

        $response = $this->postJson('/api/profile/complete', [
            'profile_image' => UploadedFile::fake()->image('profile.jpg'),
            'identity_image' => UploadedFile::fake()->image('id.jpg'),
        ]);

        $response->assertStatus(403);
    }
}
