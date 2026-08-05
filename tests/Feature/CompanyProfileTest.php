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

class CompanyProfileTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $generalManager;

    private User $hrManager;

    private User $departmentManagerUser;

    private User $employeeUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Khibrat HR',
            'email' => 'info@khibrat.test',
            'address' => 'Damascus, Syria',
            'phone' => '+963111222333',
            'status' => 'active',
        ]);

        $this->generalManager = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'General Manager',
            'email' => 'gm@khibrat.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::GeneralManager->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $this->hrManager = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'HR Manager',
            'email' => 'hr@khibrat.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::HrManager->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $department = Department::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'name' => 'Engineering',
            'is_active' => true,
        ]);

        $this->departmentManagerUser = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'Dept Manager',
            'email' => 'deptmgr@khibrat.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::DepartmentManager->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $this->employeeUser = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'Regular Employee',
            'email' => 'employee@khibrat.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::Employee->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        Employee::create([
            'id' => Str::uuid()->toString(),
            'user_id' => $this->employeeUser->id,
            'company_id' => $this->company->id,
            'department_id' => $department->id,
            'job_title' => 'Developer',
            'base_salary' => 1000,
            'hire_date' => '2022-01-01',
            'is_active' => true,
        ]);
    }

    public function test_general_manager_can_view_company_profile(): void
    {
        $this->actingAs($this->generalManager);

        $this->getJson('/api/company/profile')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $this->company->id)
            ->assertJsonPath('data.name', 'Khibrat HR')
            ->assertJsonPath('data.phone', '+963111222333')
            ->assertJsonPath('data.email', 'info@khibrat.test')
            ->assertJsonPath('data.address', 'Damascus, Syria')
            ->assertJsonPath('data.logo_url', null)
            ->assertJsonPath('data.tagline', null)
            ->assertJsonPath('data.about', null);
    }

    public function test_employee_hr_and_department_manager_can_view_but_not_update(): void
    {
        foreach (['hrManager', 'departmentManagerUser', 'employeeUser'] as $userProp) {
            $this->actingAs($this->{$userProp});

            $this->getJson('/api/company/profile')
                ->assertOk()
                ->assertJsonPath('data.name', 'Khibrat HR');

            $this->putJson('/api/company/profile', [
                'name' => 'Hacked Name',
                'phone' => '+963000000000',
                'email' => 'hacked@khibrat.test',
                'address' => 'Nowhere',
            ])->assertStatus(403);
        }

        $this->assertSame('Khibrat HR', $this->company->fresh()->name);
    }

    public function test_general_manager_can_update_text_fields_via_json(): void
    {
        $this->actingAs($this->generalManager);

        $response = $this->putJson('/api/company/profile', [
            'name' => 'Khibrat HR Solutions',
            'phone' => '+963999888777',
            'email' => 'new-info@khibrat.test',
            'address' => 'Damascus, Al Rawda',
            'tagline' => 'شريك التمكين الرقمي المعتمد',
            'about' => 'شركة رائدة في مجال الموارد البشرية.',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Company profile updated successfully.')
            ->assertJsonPath('data.name', 'Khibrat HR Solutions')
            ->assertJsonPath('data.tagline', 'شريك التمكين الرقمي المعتمد')
            ->assertJsonPath('data.about', 'شركة رائدة في مجال الموارد البشرية.');

        $fresh = $this->company->fresh();
        $this->assertSame('Khibrat HR Solutions', $fresh->name);
        $this->assertSame('+963999888777', $fresh->phone);
        $this->assertSame('new-info@khibrat.test', $fresh->email);
        $this->assertSame('Damascus, Al Rawda', $fresh->address);
        $this->assertSame('شريك التمكين الرقمي المعتمد', $fresh->tagline);
    }

    public function test_general_manager_can_upload_and_replace_logo(): void
    {
        Storage::fake('public');
        $this->actingAs($this->generalManager);

        $first = $this->call('PUT', '/api/company/profile', [
            'name' => 'Khibrat HR',
            'phone' => '+963111222333',
            'email' => 'info@khibrat.test',
            'address' => 'Damascus, Syria',
        ], [], ['logo' => UploadedFile::fake()->image('logo1.png')]);

        $first->assertOk();
        $firstLogoUrl = $first->json('data.logo_url');
        $this->assertNotNull($firstLogoUrl);

        $firstLogoPath = $this->company->fresh()->logo_path;
        Storage::disk('public')->assertExists($firstLogoPath);

        $second = $this->call('PUT', '/api/company/profile', [
            'name' => 'Khibrat HR',
            'phone' => '+963111222333',
            'email' => 'info@khibrat.test',
            'address' => 'Damascus, Syria',
        ], [], ['logo' => UploadedFile::fake()->image('logo2.png')]);

        $second->assertOk();
        $secondLogoPath = $this->company->fresh()->logo_path;

        $this->assertNotSame($firstLogoPath, $secondLogoPath);
        Storage::disk('public')->assertMissing($firstLogoPath);
        Storage::disk('public')->assertExists($secondLogoPath);
    }

    public function test_updating_without_a_new_logo_keeps_the_existing_one(): void
    {
        Storage::fake('public');
        $this->actingAs($this->generalManager);

        $this->call('PUT', '/api/company/profile', [
            'name' => 'Khibrat HR',
            'phone' => '+963111222333',
            'email' => 'info@khibrat.test',
            'address' => 'Damascus, Syria',
        ], [], ['logo' => UploadedFile::fake()->image('logo.png')])->assertOk();

        $logoPath = $this->company->fresh()->logo_path;

        $response = $this->putJson('/api/company/profile', [
            'name' => 'Khibrat HR Updated',
            'phone' => '+963111222333',
            'email' => 'info@khibrat.test',
            'address' => 'Damascus, Syria',
        ]);

        $response->assertOk();
        $this->assertSame($logoPath, $this->company->fresh()->logo_path);
        Storage::disk('public')->assertExists($logoPath);
    }

    public function test_update_requires_name_phone_email_and_address(): void
    {
        $this->actingAs($this->generalManager);

        $this->putJson('/api/company/profile', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'phone', 'email', 'address']);
    }

    public function test_each_company_only_sees_its_own_profile(): void
    {
        $otherCompany = Company::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Other Company',
            'email' => 'other@company.test',
            'address' => 'Aleppo',
            'phone' => '+963444555666',
            'status' => 'active',
        ]);

        $otherGeneralManager = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $otherCompany->id,
            'full_name' => 'Other GM',
            'email' => 'othergm@company.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::GeneralManager->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $this->actingAs($otherGeneralManager)
            ->getJson('/api/company/profile')
            ->assertOk()
            ->assertJsonPath('data.id', $otherCompany->id)
            ->assertJsonPath('data.name', 'Other Company');

        $this->actingAs($this->generalManager)
            ->getJson('/api/company/profile')
            ->assertOk()
            ->assertJsonPath('data.id', $this->company->id)
            ->assertJsonPath('data.name', 'Khibrat HR');
    }
}
