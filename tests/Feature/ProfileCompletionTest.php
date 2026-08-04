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

    public function test_employee_can_view_profile(): void
    {
        $this->employeeUser->forceFill([
            'phone' => '+963911111111',
            'gender' => 'male',
            'nationality' => 'Syrian',
            'residence' => 'Damascus',
            'birth_date' => '1995-05-20',
        ])->save();

        $this->actingAs($this->employeeUser);

        $this->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.full_name', 'Profile Employee')
            ->assertJsonPath('data.email', 'employee@profile.test')
            ->assertJsonPath('data.phone', '+963911111111')
            ->assertJsonPath('data.date_of_birth', '1995-05-20')
            ->assertJsonPath('data.gender', 'male')
            ->assertJsonPath('data.nationality', 'Syrian')
            ->assertJsonPath('data.residence', 'Damascus')
            ->assertJsonPath('data.job_title', 'Developer')
            ->assertJsonPath('data.department.name', 'Engineering')
            ->assertJsonPath('data.hire_date', '2022-01-01')
            ->assertJsonPath('data.profile_completed', false)
            ->assertJsonPath('data.profile_image_url', null);
    }

    public function test_non_employee_can_view_profile_without_error(): void
    {
        $this->actingAs($this->generalManager);

        $this->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('data.full_name', 'GM')
            ->assertJsonPath('data.job_title', null)
            ->assertJsonPath('data.department', null)
            ->assertJsonPath('data.hire_date', null)
            ->assertJsonPath('data.profile_image_url', null);
    }

    public function test_employee_can_update_phone_and_residence_via_json_put(): void
    {
        $this->actingAs($this->employeeUser);

        $this->putJson('/api/profile', [
            'phone' => '0922222222',
            'residence' => 'Aleppo',
        ])
            ->assertOk()
            ->assertJsonPath('data.phone', '0922222222')
            ->assertJsonPath('data.residence', 'Aleppo')
            ->assertJsonPath('data.profile_completed', false);

        $this->assertSame('0922222222', $this->employeeUser->fresh()->phone);
        $this->assertSame('Aleppo', $this->employeeUser->fresh()->residence);
    }

    public function test_employee_can_update_profile_via_multipart_put(): void
    {
        Storage::fake('public');
        $this->actingAs($this->employeeUser);

        $response = $this->call(
            'PUT',
            '/api/profile',
            [
                'phone' => '0944444444',
                'residence' => 'Homs',
            ],
            [],
            ['profile_image' => UploadedFile::fake()->image('avatar.jpg')]
        );

        $response->assertOk();
        $response->assertJsonPath('data.phone', '0944444444');
        $response->assertJsonPath('data.residence', 'Homs');
        $this->assertNotNull($response->json('data.profile_image_url'));
        $this->assertFalse($this->employeeUser->fresh()->profile_completed);

        $this->assertSame('0944444444', $this->employeeUser->fresh()->phone);
        $this->assertSame('Homs', $this->employeeUser->fresh()->residence);

        $document = $this->employee->document()->first();
        $this->assertNotNull($document);
        Storage::disk('public')->assertExists($document->profile_image_path);
    }

    public function test_employee_can_upload_identity_and_optional_certificate(): void
    {
        Storage::fake('public');
        $this->actingAs($this->employeeUser);

        $response = $this->post('/api/profile/documents', [
            'identity_image' => UploadedFile::fake()->image('id.jpg'),
            'university_certificate' => UploadedFile::fake()->create('certificate.pdf', 100, 'application/pdf'),
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.profile_completed', false);
        $this->assertNotNull($response->json('data.documents.identity_image_url'));
        $this->assertNotNull($response->json('data.documents.university_certificate_url'));
        $this->assertNull($response->json('data.documents.profile_image_url'));
    }

    public function test_profile_completed_becomes_true_after_profile_and_identity_uploaded(): void
    {
        Storage::fake('public');
        $this->actingAs($this->employeeUser);

        $this->call(
            'PUT',
            '/api/profile',
            [],
            [],
            ['profile_image' => UploadedFile::fake()->image('avatar.jpg')]
        )->assertOk();

        $this->assertFalse($this->employeeUser->fresh()->profile_completed);

        $this->post('/api/profile/documents', [
            'identity_image' => UploadedFile::fake()->image('id.jpg'),
        ])
            ->assertOk()
            ->assertJsonPath('data.profile_completed', true);

        $this->assertTrue($this->employeeUser->fresh()->profile_completed);
    }

    public function test_identity_image_is_required_only_the_first_time(): void
    {
        Storage::fake('public');
        $this->actingAs($this->employeeUser);

        // No identity uploaded yet - omitting it must fail.
        $this->post('/api/profile/documents', [
            'university_certificate' => UploadedFile::fake()->create('certificate.pdf', 100, 'application/pdf'),
        ])->assertStatus(422);

        // Upload identity for the first time - succeeds.
        $this->post('/api/profile/documents', [
            'identity_image' => UploadedFile::fake()->image('id.jpg'),
        ])->assertOk();

        $identityPath = $this->employee->document()->first()->identity_image_path;
        $this->assertNotNull($identityPath);

        // Now that identity exists, uploading ONLY the certificate must succeed
        // without resending identity_image, and must not touch the saved identity file.
        $response = $this->post('/api/profile/documents', [
            'university_certificate' => UploadedFile::fake()->create('certificate2.pdf', 100, 'application/pdf'),
        ]);

        $response->assertOk();
        $this->assertNotNull($response->json('data.documents.university_certificate_url'));

        $document = $this->employee->document()->first();
        $this->assertSame($identityPath, $document->identity_image_path);
        Storage::disk('public')->assertExists($identityPath);
    }

    public function test_identity_image_accepts_pdf(): void
    {
        Storage::fake('public');
        $this->actingAs($this->employeeUser);

        $response = $this->post('/api/profile/documents', [
            'identity_image' => UploadedFile::fake()->create('id.pdf', 100, 'application/pdf'),
        ]);

        $response->assertOk();
        $this->assertNotNull($response->json('data.documents.identity_image_url'));
    }

    public function test_updating_one_document_preserves_the_other(): void
    {
        Storage::fake('public');
        $this->actingAs($this->employeeUser);

        $this->post('/api/profile/documents', [
            'identity_image' => UploadedFile::fake()->image('id.jpg'),
            'university_certificate' => UploadedFile::fake()->create('certificate.pdf', 100, 'application/pdf'),
        ])->assertOk();

        $original = $this->employee->document()->first();
        $originalIdentityPath = $original->identity_image_path;
        $originalCertificatePath = $original->university_certificate_path;

        // Re-upload only the identity image.
        $this->post('/api/profile/documents', [
            'identity_image' => UploadedFile::fake()->image('id-new.jpg'),
        ])->assertOk();

        $updated = $this->employee->document()->first();
        $this->assertNotSame($originalIdentityPath, $updated->identity_image_path);
        $this->assertSame($originalCertificatePath, $updated->university_certificate_path);
        Storage::disk('public')->assertExists($updated->identity_image_path);
        Storage::disk('public')->assertExists($originalCertificatePath);
    }

    public function test_update_ignores_non_editable_fields(): void
    {
        $this->actingAs($this->employeeUser);

        $this->putJson('/api/profile', [
            'phone' => '0933333333',
            'full_name' => 'Hacker Name',
            'email' => 'hacked@profile.test',
            'gender' => 'female',
            'nationality' => 'Other',
            'job_title' => 'CEO',
        ])
            ->assertOk()
            ->assertJsonPath('data.phone', '0933333333')
            ->assertJsonPath('data.full_name', 'Profile Employee')
            ->assertJsonPath('data.email', 'employee@profile.test')
            ->assertJsonPath('data.job_title', 'Developer');
    }

    public function test_documents_upload_requires_employee_record(): void
    {
        Storage::fake('public');
        $this->actingAs($this->generalManager);

        $this->post('/api/profile/documents', [
            'identity_image' => UploadedFile::fake()->image('id.jpg'),
        ])->assertStatus(403);
    }

    public function test_profile_complete_route_no_longer_exists(): void
    {
        Storage::fake('public');
        $this->actingAs($this->employeeUser);

        $this->post('/api/profile/complete', [
            'profile_image' => UploadedFile::fake()->image('profile.jpg'),
            'identity_image' => UploadedFile::fake()->image('id.jpg'),
        ])->assertNotFound();
    }
}
