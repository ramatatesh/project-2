<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class BirthDateSupportTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Department $department;

    private User $hrManager;

    private User $generalManager;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->company = Company::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Birth Date Co',
            'email' => 'birthdate@company.test',
            'address' => 'Address',
            'phone' => '0911111111',
            'status' => 'active',
        ]);

        $this->department = Department::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'name' => 'Human Resources',
            'is_active' => true,
        ]);

        $this->hrManager = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'HR Manager',
            'email' => 'hr@birthdate.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::HrManager->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $this->generalManager = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'General Manager',
            'email' => 'gm@birthdate.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::GeneralManager->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);
    }

    public function test_creating_an_employee_saves_the_birth_date(): void
    {
        $response = $this->actingAs($this->hrManager)->postJson('/api/hr/employees', [
            'full_name' => 'New Employee',
            'email' => 'newemp@birthdate.test',
            'department_id' => $this->department->id,
            'job_title' => 'Developer',
            'base_salary' => 1000,
            'hire_date' => now()->toDateString(),
            'birth_date' => '1995-05-20',
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('users', [
            'email' => 'newemp@birthdate.test',
            'birth_date' => '1995-05-20',
        ]);
    }

    public function test_creating_an_employee_rejects_a_future_birth_date(): void
    {
        $response = $this->actingAs($this->hrManager)->postJson('/api/hr/employees', [
            'full_name' => 'Future Baby',
            'email' => 'future@birthdate.test',
            'department_id' => $this->department->id,
            'job_title' => 'Developer',
            'base_salary' => 1000,
            'hire_date' => now()->toDateString(),
            'birth_date' => now()->addYears(1)->toDateString(),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['birth_date']);
        $this->assertDatabaseMissing('users', ['email' => 'future@birthdate.test']);
    }

    public function test_updating_an_employee_changes_the_birth_date(): void
    {
        $employeeUser = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'Existing Employee',
            'email' => 'existing@birthdate.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::Employee->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $employee = Employee::create([
            'id' => Str::uuid()->toString(),
            'user_id' => $employeeUser->id,
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'job_title' => 'Developer',
            'base_salary' => 1000,
            'hire_date' => '2022-01-01',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->hrManager)->putJson("/api/hr/employees/{$employee->id}", [
            'birth_date' => '1990-01-01',
        ]);

        $response->assertOk();
        $this->assertSame('1990-01-01', $employeeUser->fresh()->birth_date);
    }

    public function test_creating_an_hr_manager_saves_the_birth_date(): void
    {
        $response = $this->actingAs($this->generalManager)->postJson("/api/companies/{$this->company->id}/hr-managers", [
            'full_name' => 'New HR',
            'email' => 'newhr@birthdate.test',
            'job_title' => 'HR Manager',
            'base_salary' => 1200,
            'hire_date' => now()->toDateString(),
            'birth_date' => '1988-08-08',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', [
            'email' => 'newhr@birthdate.test',
            'birth_date' => '1988-08-08',
        ]);
    }

    public function test_creating_a_department_manager_saves_the_birth_date(): void
    {
        $engineering = Department::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'name' => 'Engineering',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->hrManager)->postJson('/api/hr/department-managers', [
            'full_name' => 'New Dept Manager',
            'email' => 'newdeptmgr@birthdate.test',
            'department_id' => $engineering->id,
            'job_title' => 'Engineering Manager',
            'base_salary' => 2000,
            'hire_date' => now()->toDateString(),
            'birth_date' => '1985-03-15',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', [
            'email' => 'newdeptmgr@birthdate.test',
            'birth_date' => '1985-03-15',
        ]);
    }

    public function test_excel_import_saves_birth_date_when_present(): void
    {
        Queue::fake();
        $this->actingAs($this->hrManager);

        $export = new class implements FromArray, WithHeadings
        {
            public function headings(): array
            {
                return ['full_name', 'email', 'phone', 'department', 'education', 'job_title', 'base_salary', 'hire_date', 'employment_type', 'birth_date'];
            }

            public function array(): array
            {
                return [
                    ['Imported Person', 'imported@birthdate.test', '0922222222', 'Human Resources', 'BSc', 'Analyst', 1600, '2024-02-01', 'full-time', '1992-11-11'],
                ];
            }
        };

        $path = 'imports/birth_date_test.xlsx';
        Excel::store($export, $path);
        $file = UploadedFile::fake()->createWithContent('birth_date_test.xlsx', Storage::disk('local')->get($path));

        $this->postJson('/api/hr/employees/import', ['file' => $file])
            ->assertOk()
            ->assertJson(['success' => true, 'count' => 1]);

        $this->assertDatabaseHas('users', [
            'email' => 'imported@birthdate.test',
            'birth_date' => '1992-11-11',
        ]);
    }

    public function test_excel_import_rejects_future_birth_date(): void
    {
        $this->actingAs($this->hrManager);

        $export = new class implements FromArray, WithHeadings
        {
            public function headings(): array
            {
                return ['full_name', 'email', 'phone', 'department', 'education', 'job_title', 'base_salary', 'hire_date', 'employment_type', 'birth_date'];
            }

            public function array(): array
            {
                return [
                    ['Future Person', 'futureimport@birthdate.test', '0922222222', 'Human Resources', 'BSc', 'Analyst', 1600, '2024-02-01', 'full-time', '2999-01-01'],
                ];
            }
        };

        $path = 'imports/birth_date_future_test.xlsx';
        Excel::store($export, $path);
        $file = UploadedFile::fake()->createWithContent('birth_date_future_test.xlsx', Storage::disk('local')->get($path));

        $response = $this->postJson('/api/hr/employees/import', ['file' => $file]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.2.birth_date', ['birth_date cannot be in the future.']);
        $this->assertDatabaseMissing('users', ['email' => 'futureimport@birthdate.test']);
    }

    public function test_import_template_includes_birth_date_column(): void
    {
        $this->actingAs($this->hrManager);

        $response = $this->getJson('/api/hr/employees/import/template');

        $response->assertOk();
    }
}
