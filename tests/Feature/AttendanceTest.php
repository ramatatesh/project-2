<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AttendancePolicy;
use App\Models\AttendanceRecord;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\HolidayPolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\AttendanceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Department $department;

    private User $hrManager;

    private User $employeeUser;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Attendance Co',
            'email' => 'attendance@company.test',
            'address' => 'Damascus',
            'phone' => '+963000000',
            'status' => 'active',
        ]);

        $this->department = Department::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'name' => 'Engineering',
            'is_active' => true,
        ]);

        $this->hrManager = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'HR Manager',
            'email' => 'hr@attendance.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::HrManager->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $this->employeeUser = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'Attendance Employee',
            'email' => 'employee@attendance.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::Employee->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $this->employee = Employee::create([
            'id' => Str::uuid()->toString(),
            'user_id' => $this->employeeUser->id,
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'employee_code' => 'EMP-ATT-1',
            'job_title' => 'Developer',
            'base_salary' => 1000,
            'hire_date' => '2022-01-01',
            'employment_type' => 'full-time',
            'is_active' => true,
        ]);
    }

    private function currentQrToken(): string
    {
        return app(AttendanceService::class)->currentQrToken($this->company->id)['token'];
    }

    public function test_management_qr_code_endpoint_returns_token_and_rendered_image(): void
    {
        $this->actingAs($this->hrManager);

        $response = $this->getJson('/api/management/attendance/qr-code');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $this->assertNotEmpty($response->json('data.token'));
        $this->assertIsInt($response->json('data.expires_in_seconds'));

        $qrImage = $response->json('data.qr_image');
        $this->assertStringStartsWith('data:image/png;base64,', $qrImage);

        $base64 = substr($qrImage, strlen('data:image/png;base64,'));
        $this->assertNotFalse(base64_decode($base64, true), 'qr_image payload must be valid base64.');
    }

    public function test_employee_can_check_in_with_valid_qr_code(): void
    {
        $this->actingAs($this->employeeUser);

        $response = $this->postJson('/api/employee/attendance/check-in', [
            'qr_token' => $this->currentQrToken(),
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.status', AttendanceRecord::STATUS_CHECKED_IN);

        $this->assertDatabaseHas('attendance_records', [
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'status' => AttendanceRecord::STATUS_CHECKED_IN,
        ]);
    }

    public function test_check_in_is_rejected_with_invalid_qr_token(): void
    {
        $this->actingAs($this->employeeUser);

        $response = $this->postJson('/api/employee/attendance/check-in', [
            'qr_token' => '123456.not-a-real-signature',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_duplicate_check_in_same_day_is_rejected(): void
    {
        $this->actingAs($this->employeeUser);

        $this->postJson('/api/employee/attendance/check-in', ['qr_token' => $this->currentQrToken()])
            ->assertStatus(201);

        $response = $this->postJson('/api/employee/attendance/check-in', ['qr_token' => $this->currentQrToken()]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'You have already checked in today.']);
        $this->assertDatabaseCount('attendance_records', 1);
    }

    public function test_employee_can_check_out_after_checking_in(): void
    {
        $this->actingAs($this->employeeUser);

        $this->postJson('/api/employee/attendance/check-in', ['qr_token' => $this->currentQrToken()])
            ->assertStatus(201);

        $response = $this->postJson('/api/employee/attendance/check-out', ['qr_token' => $this->currentQrToken()]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', AttendanceRecord::STATUS_COMPLETED);
        $this->assertNotNull($response->json('data.total_work_minutes'));

        $this->assertDatabaseHas('attendance_records', [
            'employee_id' => $this->employee->id,
            'status' => AttendanceRecord::STATUS_COMPLETED,
        ]);
    }

    public function test_check_out_without_check_in_is_rejected(): void
    {
        $this->actingAs($this->employeeUser);

        $response = $this->postJson('/api/employee/attendance/check-out', ['qr_token' => $this->currentQrToken()]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'You have not checked in today or your attendance is already completed.']);
    }

    public function test_check_in_is_rejected_when_outside_gps_radius(): void
    {
        AttendancePolicy::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'enable_gps_verification' => true,
            'latitude' => 33.5138,
            'longitude' => 36.2765,
            'allowed_perimeter' => 100,
        ]);

        $this->actingAs($this->employeeUser);

        $response = $this->postJson('/api/employee/attendance/check-in', [
            'qr_token' => $this->currentQrToken(),
            'latitude' => 33.5238, // roughly ~1.1km away, well outside a 100m radius
            'longitude' => 36.2765,
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'You are outside the allowed check-in location radius.']);
        $this->assertDatabaseCount('attendance_records', 0);

        $this->assertDatabaseHas('attendance_location_logs', [
            'is_within_radius' => false,
        ]);
    }

    public function test_check_in_is_accepted_when_inside_gps_radius(): void
    {
        AttendancePolicy::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'enable_gps_verification' => true,
            'latitude' => 33.5138,
            'longitude' => 36.2765,
            'allowed_perimeter' => 100,
        ]);

        $this->actingAs($this->employeeUser);

        $response = $this->postJson('/api/employee/attendance/check-in', [
            'qr_token' => $this->currentQrToken(),
            'latitude' => 33.5138,
            'longitude' => 36.2765,
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('attendance_location_logs', [
            'is_within_radius' => true,
        ]);
    }

    public function test_hr_manager_can_adjust_attendance_and_minutes_are_recalculated(): void
    {
        AttendancePolicy::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'work_start_time' => '08:00:00',
            'work_end_time' => '17:00:00',
            'allowed_late_minutes' => 15,
            'allowed_early_leave_minutes' => 15,
        ]);

        $today = Carbon::today();

        $record = AttendanceRecord::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'work_date' => $today->toDateString(),
            'check_in_time' => $today->copy()->setTime(8, 5),
            'check_out_time' => $today->copy()->setTime(17, 0),
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
            'total_work_minutes' => 535,
            'status' => AttendanceRecord::STATUS_COMPLETED,
            'attendance_type' => AttendanceRecord::TYPE_PRESENT,
        ]);

        $this->actingAs($this->hrManager);

        $response = $this->putJson("/api/management/attendance/{$record->id}/adjust", [
            'new_check_in' => $today->copy()->setTime(9, 0)->toDateTimeString(),
            'reason' => 'Forgot to badge in on time, confirmed with manager.',
        ]);

        $response->assertStatus(200);
        // 9:00 check-in vs 08:00 start + 15 min grace => 45 minutes late.
        $response->assertJsonPath('data.late_minutes', 45);
        $response->assertJsonPath('data.attendance_type', AttendanceRecord::TYPE_LATE);

        $this->assertDatabaseHas('attendance_adjustments', [
            'attendance_record_id' => $record->id,
            'adjusted_by' => $this->hrManager->id,
        ]);
    }

    public function test_absence_job_marks_absent_but_skips_approved_leave(): void
    {
        $leaveType = LeaveType::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'name' => 'Annual',
            'allocation_value' => 20,
            'allocation_unit' => 'days',
            'requires_proof' => false,
            'is_active' => true,
        ]);

        $employeeOnLeaveUser = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'On Leave Employee',
            'email' => 'onleave@attendance.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::Employee->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $employeeOnLeave = Employee::create([
            'id' => Str::uuid()->toString(),
            'user_id' => $employeeOnLeaveUser->id,
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'employee_code' => 'EMP-ATT-2',
            'job_title' => 'Developer',
            'base_salary' => 1000,
            'hire_date' => '2022-01-01',
            'employment_type' => 'full-time',
            'is_active' => true,
        ]);

        $yesterday = Carbon::yesterday();

        LeaveRequest::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'employee_id' => $employeeOnLeave->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => $yesterday->toDateString(),
            'end_date' => $yesterday->toDateString(),
            'requested_value' => 1,
            'status' => 'approved',
        ]);

        $this->artisan('attendance:mark-absent', ['--date' => $yesterday->toDateString()])
            ->assertExitCode(0);

        $this->assertDatabaseHas('attendance_records', [
            'employee_id' => $this->employee->id,
            'work_date' => $yesterday->toDateString(),
            'status' => AttendanceRecord::STATUS_ABSENT,
        ]);

        $this->assertDatabaseMissing('attendance_records', [
            'employee_id' => $employeeOnLeave->id,
            'work_date' => $yesterday->toDateString(),
        ]);
    }

    public function test_absence_job_skips_weekly_holiday(): void
    {
        $yesterday = Carbon::yesterday();

        HolidayPolicy::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'weekly_holidays' => [strtolower($yesterday->format('l'))],
        ]);

        $this->artisan('attendance:mark-absent', ['--date' => $yesterday->toDateString()])
            ->assertExitCode(0);

        $this->assertDatabaseMissing('attendance_records', [
            'employee_id' => $this->employee->id,
            'work_date' => $yesterday->toDateString(),
        ]);
    }

    public function test_department_manager_only_sees_own_department_attendance(): void
    {
        $otherDepartment = Department::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'name' => 'Sales',
            'is_active' => true,
        ]);

        $managerUser = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'Dept Manager',
            'email' => 'deptmanager@attendance.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::DepartmentManager->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $managerEmployee = Employee::create([
            'id' => Str::uuid()->toString(),
            'user_id' => $managerUser->id,
            'company_id' => $this->company->id,
            'department_id' => $otherDepartment->id,
            'employee_code' => 'EMP-MGR',
            'job_title' => 'Sales Manager',
            'base_salary' => 2000,
            'hire_date' => '2020-01-01',
            'employment_type' => 'full-time',
            'is_active' => true,
        ]);

        $otherDepartment->update(['manager_id' => $managerEmployee->id]);

        // Attendance record belongs to the Engineering department (not managed by this manager).
        AttendanceRecord::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'work_date' => Carbon::today()->toDateString(),
            'status' => AttendanceRecord::STATUS_CHECKED_IN,
            'attendance_type' => AttendanceRecord::TYPE_PRESENT,
            'check_in_time' => now(),
        ]);

        $this->actingAs($managerUser);

        $response = $this->getJson('/api/management/attendance');

        $response->assertStatus(200);
        $this->assertSame(0, $response->json('data.total'));
    }
}
