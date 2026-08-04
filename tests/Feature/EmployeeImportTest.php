<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Jobs\SendEmployeeWelcomeEmailJob;
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
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Tests\TestCase;

class EmployeeImportTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Department $department;
    private User $hrManager;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->company = Company::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Test Co',
            'email' => 'test@company.test',
            'address' => 'Address',
            'phone' => '+963000000',
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
            'email' => 'hr@company.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::HrManager->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);
    }

    public function test_employees_can_be_imported_using_department_name_and_various_date_formats(): void
    {
        Queue::fake();
        $this->actingAs($this->hrManager);

        $rows = [
            ['John Doe', 'john@company.test', '+963111111111', 'Human Resources', 'BSc', 'Developer', 1500, '2024-01-01', 'full-time'],
            ['Jane Doe', 'jane@company.test', '+963222222222', 'human resources', 'MSc', 'Designer', 1800, '01/01/2024', 'part-time'],
            ['Bob Smith', 'bob@company.test', '+963333333333', '  Human Resources  ', 'PhD', 'Manager', 2500, Date::PHPToExcel(new \DateTime('2024-01-01')), 'contract'],
        ];

        $file = $this->makeImportFile($rows);

        $response = $this->postJson('/api/hr/employees/import', [
            'file' => $file,
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Employees imported successfully.',
                'count' => 3,
            ]);

        $this->assertCount(3, Employee::all());
        $this->assertCount(4, User::all()); // HR manager + 3 imported employees

        $this->assertDatabaseHas('users', ['email' => 'john@company.test']);
        $this->assertDatabaseHas('users', ['email' => 'jane@company.test']);
        $this->assertDatabaseHas('users', ['email' => 'bob@company.test']);
        $this->assertDatabaseHas('employees', ['job_title' => 'Developer', 'hire_date' => '2024-01-01']);
        $this->assertDatabaseHas('employees', ['job_title' => 'Designer', 'hire_date' => '2024-01-01']);
        $this->assertDatabaseHas('employees', ['job_title' => 'Manager', 'hire_date' => '2024-01-01']);

        Queue::assertPushed(SendEmployeeWelcomeEmailJob::class, 3);
    }

    public function test_import_fails_all_or_nothing_and_returns_row_errors(): void
    {
        $this->actingAs($this->hrManager);

        $rows = [
            ['Valid User', 'valid@company.test', '+963111111111', 'Human Resources', 'BSc', 'Developer', 1500, '2024-01-01', 'full-time'],
            ['Bad Department', 'baddept@company.test', '+963111111111', 'Unknown Department', 'BSc', 'Developer', 1500, '2024-01-01', 'full-time'],
            ['Bad Date', 'baddate@company.test', '+963111111111', 'Human Resources', 'BSc', 'Developer', 1500, 'not-a-date', 'full-time'],
        ];

        $file = $this->makeImportFile($rows);

        $response = $this->postJson('/api/hr/employees/import', [
            'file' => $file,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Import failed. No employees were added.',
            ])
            ->assertJsonPath('errors.3.department', ['department not found.'])
            ->assertJsonPath('errors.4.hire_date', ['hire_date is required and must be a valid date (Y-m-d).']);

        $this->assertCount(0, Employee::all());
        $this->assertDatabaseMissing('users', ['email' => 'valid@company.test']);
    }

    public function test_import_ignores_blank_rows_and_extra_empty_columns(): void
    {
        Queue::fake();
        $this->actingAs($this->hrManager);

        $export = new class implements FromArray, WithHeadings
        {
            public function headings(): array
            {
                return ['full_name', 'email', 'phone', 'department', 'education', 'job_title', 'base_salary', 'hire_date', 'employment_type', ''];
            }

            public function array(): array
            {
                return [
                    ['Alice', 'alice@company.test', '+963444444444', 'Human Resources', 'BSc', 'Engineer', 2000, '2024-01-01', 'full-time', ''],
                    ['', '', '', '', '', '', '', '', '', ''],
                ];
            }
        };

        $path = 'imports/blank_test.xlsx';
        Excel::store($export, $path);
        $file = UploadedFile::fake()->createWithContent('blank_test.xlsx', Storage::disk('local')->get($path));

        $this->postJson('/api/hr/employees/import', ['file' => $file])
            ->assertOk()
            ->assertJson(['success' => true, 'count' => 1]);
    }

    public function test_import_saves_gender_marital_status_nationality_and_residence_when_present(): void
    {
        Queue::fake();
        $this->actingAs($this->hrManager);

        $export = new class implements FromArray, WithHeadings
        {
            public function headings(): array
            {
                return ['full_name', 'email', 'phone', 'department', 'education', 'job_title', 'base_salary', 'hire_date', 'employment_type', 'gender', 'marital_status', 'nationality', 'residence'];
            }

            public function array(): array
            {
                return [
                    ['Sara Extra', 'sara@company.test', '+963111111111', 'Human Resources', 'BSc', 'Analyst', 1600, '2024-02-01', 'full-time', 'Female', 'Married', 'Syrian', 'Homs'],
                ];
            }
        };

        $path = 'imports/extra_fields_test.xlsx';
        Excel::store($export, $path);
        $file = UploadedFile::fake()->createWithContent('extra_fields_test.xlsx', Storage::disk('local')->get($path));

        $this->postJson('/api/hr/employees/import', ['file' => $file])
            ->assertOk()
            ->assertJson(['success' => true, 'count' => 1]);

        $this->assertDatabaseHas('users', [
            'email' => 'sara@company.test',
            'gender' => 'female',
            'marital_status' => 'married',
            'nationality' => 'Syrian',
            'residence' => 'Homs',
        ]);
    }

    public function test_import_rejects_invalid_gender_and_marital_status_values(): void
    {
        $this->actingAs($this->hrManager);

        $export = new class implements FromArray, WithHeadings
        {
            public function headings(): array
            {
                return ['full_name', 'email', 'phone', 'department', 'education', 'job_title', 'base_salary', 'hire_date', 'employment_type', 'gender', 'marital_status'];
            }

            public function array(): array
            {
                return [
                    ['Bad Enum', 'badenum@company.test', '+963111111111', 'Human Resources', 'BSc', 'Analyst', 1600, '2024-02-01', 'full-time', 'unknown', 'engaged'],
                ];
            }
        };

        $path = 'imports/bad_enum_test.xlsx';
        Excel::store($export, $path);
        $file = UploadedFile::fake()->createWithContent('bad_enum_test.xlsx', Storage::disk('local')->get($path));

        $response = $this->postJson('/api/hr/employees/import', ['file' => $file]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.2.gender', ['gender must be male or female.']);
        $response->assertJsonPath('errors.2.marital_status', ['marital_status must be one of: single, married, divorced, widowed.']);

        $this->assertDatabaseMissing('users', ['email' => 'badenum@company.test']);
    }

    public function test_template_download_returns_xlsx(): void
    {
        $this->actingAs($this->hrManager);

        $response = $this->get('/api/hr/employees/import/template');

        $response->assertOk();
        $this->assertTrue($response->headers->contains('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'));
        $response->assertHeader('content-disposition');
    }

    private function makeImportFile(array $rows): UploadedFile
    {
        $export = new class($rows) implements FromArray, WithHeadings
        {
            public function __construct(private readonly array $rows)
            {
            }

            public function headings(): array
            {
                return ['full_name', 'email', 'phone', 'department', 'education', 'job_title', 'base_salary', 'hire_date', 'employment_type'];
            }

            public function array(): array
            {
                return $this->rows;
            }
        };

        $path = 'imports/'.Str::random(8).'.xlsx';
        Excel::store($export, $path);

        return UploadedFile::fake()->createWithContent('import.xlsx', Storage::disk('local')->get($path));
    }
}
