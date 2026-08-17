<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AttendanceRecord;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\EvaluationCycle;
use App\Models\EvaluationScore;
use App\Models\EvaluationTemplate;
use App\Models\Holiday;
use App\Models\HolidayPolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EmployeeProfileOverviewTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $hrManager;

    private User $generalManager;

    private Department $department;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Profile Overview Co',
            'email' => 'profileoverview@company.test',
            'address' => 'Damascus',
            'phone' => '0911111111',
            'status' => 'active',
        ]);

        $this->hrManager = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'HR Manager',
            'email' => 'hr@profileoverview.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::HrManager->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $this->generalManager = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'General Manager',
            'email' => 'gm@profileoverview.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::GeneralManager->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $this->department = Department::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'name' => 'Engineering',
            'is_active' => true,
        ]);

        $employeeUser = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'Ahmad Al-Ali',
            'email' => 'ahmad@profileoverview.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::Employee->value,
            'status' => 'active',
            'is_first_login' => false,
            'phone' => '0999999999',
            'gender' => 'male',
            'marital_status' => 'single',
            'nationality' => 'Syrian',
            'residence' => 'Damascus',
            'birth_date' => '1994-05-12',
        ]);

        $this->employee = Employee::create([
            'id' => Str::uuid()->toString(),
            'user_id' => $employeeUser->id,
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'education' => 'BSc Computer Science',
            'job_title' => 'Senior Developer',
            'base_salary' => 1200,
            'hire_date' => '2024-01-15',
            'employment_type' => 'full-time',
            'is_active' => true,
        ]);

        EmployeeDocument::create([
            'id' => Str::uuid()->toString(),
            'employee_id' => $this->employee->id,
            'profile_image_path' => 'employee_documents/x/profile.png',
            'identity_image_path' => 'employee_documents/x/id.png',
            'university_certificate_path' => null,
        ]);
    }

    public function test_hr_manager_sees_the_full_profile_overview(): void
    {
        // Evaluation: one finalized excellent-band score, no numeric score exposed.
        $template = EvaluationTemplate::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'name' => 'Annual Review',
            'is_active' => true,
        ]);

        $cycle = EvaluationCycle::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'evaluation_template_id' => $template->id,
            'name' => '2026 H1',
            'start_date' => '2026-01-01',
            'end_date' => '2026-06-30',
            'status' => EvaluationCycle::STATUS_CLOSED,
        ]);

        EvaluationScore::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'evaluation_cycle_id' => $cycle->id,
            'employee_id' => $this->employee->id,
            'manager_score' => 9,
            'self_score' => 9,
            'peer_score' => 9,
            'final_score' => 9,
            'status' => EvaluationScore::STATUS_FINALIZED,
            'finalized_by' => $this->hrManager->id,
            'finalized_at' => now(),
        ]);

        // Attendance: one present day, one late day, one absent day - all within employment.
        AttendanceRecord::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'work_date' => '2026-08-10',
            'check_in_time' => '2026-08-10 09:00:00',
            'check_out_time' => '2026-08-10 17:00:00',
            'status' => AttendanceRecord::STATUS_COMPLETED,
            'attendance_type' => AttendanceRecord::TYPE_PRESENT,
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
        ]);

        AttendanceRecord::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'work_date' => '2026-08-11',
            'check_in_time' => '2026-08-11 09:20:00',
            'check_out_time' => '2026-08-11 17:00:00',
            'status' => AttendanceRecord::STATUS_COMPLETED,
            'attendance_type' => AttendanceRecord::TYPE_LATE,
            'late_minutes' => 20,
            'early_leave_minutes' => 0,
        ]);

        AttendanceRecord::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'work_date' => '2026-08-12',
            'check_in_time' => null,
            'check_out_time' => null,
            'status' => AttendanceRecord::STATUS_ABSENT,
            'attendance_type' => AttendanceRecord::TYPE_ABSENT,
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
        ]);

        // Approved leave request spanning 2 days.
        $leaveType = LeaveType::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'name' => 'Annual Leave',
            'allocation_value' => 21,
            'allocation_unit' => 'day',
            'requires_proof' => false,
            'is_active' => true,
        ]);

        LeaveRequest::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-08-13',
            'end_date' => '2026-08-14',
            'requested_value' => 2,
            'status' => 'approved',
        ]);

        // A rejected leave request must NOT appear in the history.
        LeaveRequest::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-08-20',
            'end_date' => '2026-08-20',
            'requested_value' => 1,
            'status' => 'rejected',
        ]);

        // A one-off holiday and an annually-repeating holiday, both inside the employment window.
        Holiday::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'name' => 'Company Anniversary',
            'holiday_type' => 'single_day',
            'start_date' => '2026-03-01',
            'end_date' => null,
            'repeats_annually' => false,
        ]);

        Holiday::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'name' => 'Independence Day',
            'holiday_type' => 'single_day',
            'start_date' => '2026-04-17',
            'end_date' => null,
            'repeats_annually' => true,
        ]);

        HolidayPolicy::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'weekly_holidays' => ['friday', 'saturday'],
        ]);

        $response = $this->actingAs($this->hrManager)
            ->getJson("/api/hr/employees/{$this->employee->id}/profile-overview");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.personal_info.full_name', 'Ahmad Al-Ali')
            ->assertJsonPath('data.personal_info.email', 'ahmad@profileoverview.test')
            ->assertJsonPath('data.personal_info.gender', 'male')
            ->assertJsonPath('data.personal_info.birth_date', '1994-05-12')
            ->assertJsonPath('data.personal_info.job_title', 'Senior Developer')
            ->assertJsonPath('data.personal_info.department.name', 'Engineering')
            ->assertJsonPath('data.personal_info.documents.university_certificate_url', null)
            ->assertJsonPath('data.evaluation_ratings.0.rating', 'excellent')
            ->assertJsonPath('data.attendance_history.summary.present_days', 1)
            ->assertJsonPath('data.attendance_history.summary.late_days', 1)
            ->assertJsonPath('data.attendance_history.summary.absent_days', 1)
            ->assertJsonPath('data.attendance_history.summary.leave_days', 2)
            ->assertJsonCount(1, 'data.attendance_history.leave_requests')
            ->assertJsonPath('data.attendance_history.weekly_holiday_days', ['friday', 'saturday']);

        $documentsUrl = $response->json('data.personal_info.documents.profile_image_url');
        $this->assertNotNull($documentsUrl);
        $this->assertStringContainsString('profile.png', $documentsUrl);

        $holidayNames = collect($response->json('data.attendance_history.holidays'))->pluck('name')->all();
        $this->assertContains('Company Anniversary', $holidayNames);
        $this->assertContains('Independence Day', $holidayNames);
    }

    public function test_general_manager_can_also_view_the_profile_overview(): void
    {
        $this->actingAs($this->generalManager)
            ->getJson("/api/hr/employees/{$this->employee->id}/profile-overview")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.personal_info.full_name', 'Ahmad Al-Ali');
    }

    public function test_employee_role_is_forbidden(): void
    {
        $employeeUser = $this->employee->user;

        $this->actingAs($employeeUser)
            ->getJson("/api/hr/employees/{$this->employee->id}/profile-overview")
            ->assertStatus(403);
    }

    public function test_employee_from_another_company_returns_404(): void
    {
        $otherCompany = Company::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Other Co',
            'email' => 'other@profileoverview.test',
            'address' => 'Aleppo',
            'phone' => '0922222222',
            'status' => 'active',
        ]);

        $otherUser = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $otherCompany->id,
            'full_name' => 'Other Employee',
            'email' => 'otheremployee@profileoverview.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::Employee->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $otherDepartment = Department::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $otherCompany->id,
            'name' => 'QA',
            'is_active' => true,
        ]);

        $otherEmployee = Employee::create([
            'id' => Str::uuid()->toString(),
            'user_id' => $otherUser->id,
            'company_id' => $otherCompany->id,
            'department_id' => $otherDepartment->id,
            'job_title' => 'Tester',
            'base_salary' => 900,
            'hire_date' => '2025-01-01',
            'employment_type' => 'full-time',
            'is_active' => true,
        ]);

        $this->actingAs($this->hrManager)
            ->getJson("/api/hr/employees/{$otherEmployee->id}/profile-overview")
            ->assertStatus(404);
    }

    public function test_missing_evaluation_and_attendance_data_returns_empty_lists_not_an_error(): void
    {
        $response = $this->actingAs($this->hrManager)
            ->getJson("/api/hr/employees/{$this->employee->id}/profile-overview");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(0, 'data.evaluation_ratings')
            ->assertJsonCount(0, 'data.attendance_history.attendance_records')
            ->assertJsonCount(0, 'data.attendance_history.leave_requests');
    }
}
