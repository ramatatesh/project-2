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

    private function checkInPayload(array $extra = []): array
    {
        return array_merge([
            'qr_token' => $this->currentQrToken(),
            'device_id' => 'test-device-employee-1',
        ], $extra);
    }

    private function checkOutPayload(array $extra = []): array
    {
        return array_merge([
            'qr_token' => $this->currentQrToken(),
            'device_id' => 'test-device-employee-1',
        ], $extra);
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

        $response = $this->postJson('/api/employee/attendance/check-in', $this->checkInPayload());

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.status', AttendanceRecord::STATUS_CHECKED_IN);
        $response->assertJsonPath('device_bound_now', true);

        $this->assertDatabaseHas('attendance_records', [
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'status' => AttendanceRecord::STATUS_CHECKED_IN,
            'check_in_device_id' => 'test-device-employee-1',
        ]);

        $this->assertDatabaseHas('employee_devices', [
            'employee_id' => $this->employee->id,
            'device_id' => 'test-device-employee-1',
            'is_active' => true,
        ]);
    }

    public function test_check_in_is_rejected_with_invalid_qr_token(): void
    {
        $this->actingAs($this->employeeUser);

        $response = $this->postJson('/api/employee/attendance/check-in', $this->checkInPayload([
            'qr_token' => '123456.not-a-real-signature',
        ]));

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_duplicate_check_in_same_day_is_rejected(): void
    {
        $this->actingAs($this->employeeUser);

        $this->postJson('/api/employee/attendance/check-in', $this->checkInPayload())
            ->assertStatus(201);

        $response = $this->postJson('/api/employee/attendance/check-in', $this->checkInPayload());

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'You have already checked in today.']);
        $this->assertDatabaseCount('attendance_records', 1);
    }

    public function test_employee_can_check_out_after_checking_in(): void
    {
        $this->actingAs($this->employeeUser);

        $this->postJson('/api/employee/attendance/check-in', $this->checkInPayload())
            ->assertStatus(201);

        $response = $this->postJson('/api/employee/attendance/check-out', $this->checkOutPayload());

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

        $response = $this->postJson('/api/employee/attendance/check-out', $this->checkOutPayload());

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

        $response = $this->postJson('/api/employee/attendance/check-in', $this->checkInPayload([
            'latitude' => 33.5238, // roughly ~1.1km away, well outside a 100m radius
            'longitude' => 36.2765,
        ]));

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

        $response = $this->postJson('/api/employee/attendance/check-in', $this->checkInPayload([
            'latitude' => 33.5138,
            'longitude' => 36.2765,
        ]));

        $response->assertStatus(201);

        $this->assertDatabaseHas('attendance_location_logs', [
            'is_within_radius' => true,
        ]);
    }

    public function test_buddy_punching_same_device_for_second_employee_is_rejected(): void
    {
        $otherUser = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'Other Employee',
            'email' => 'other@attendance.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::Employee->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $otherEmployee = Employee::create([
            'id' => Str::uuid()->toString(),
            'user_id' => $otherUser->id,
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'job_title' => 'Developer',
            'base_salary' => 1000,
            'hire_date' => '2022-01-01',
            'employment_type' => 'full-time',
            'is_active' => true,
        ]);

        $this->actingAs($this->employeeUser);
        $this->postJson('/api/employee/attendance/check-in', $this->checkInPayload([
            'device_id' => 'shared-phone',
        ]))->assertStatus(201);

        $this->actingAs($otherUser);
        $response = $this->postJson('/api/employee/attendance/check-in', [
            'qr_token' => $this->currentQrToken(),
            'device_id' => 'shared-phone',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'device_bound_to_other');
        $this->assertDatabaseCount('attendance_records', 1);
        unset($otherEmployee);
    }

    public function test_check_in_from_different_device_is_rejected_until_hr_unbinds(): void
    {
        $this->actingAs($this->employeeUser);
        $this->postJson('/api/employee/attendance/check-in', $this->checkInPayload([
            'device_id' => 'old-phone',
        ]))->assertStatus(201);

        AttendanceRecord::query()->delete();

        $response = $this->postJson('/api/employee/attendance/check-in', $this->checkInPayload([
            'device_id' => 'new-phone',
        ]));

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'device_mismatch');

        $this->actingAs($this->hrManager);
        $this->postJson("/api/hr/employees/{$this->employee->id}/device/unbind", [
            'reason' => 'Employee replaced lost phone',
        ])->assertStatus(200);

        $this->actingAs($this->employeeUser);
        $this->postJson('/api/employee/attendance/check-in', $this->checkInPayload([
            'device_id' => 'new-phone',
        ]))->assertStatus(201);

        $this->assertDatabaseHas('employee_devices', [
            'employee_id' => $this->employee->id,
            'device_id' => 'new-phone',
            'is_active' => true,
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

    public function test_manual_adjustment_rejects_check_out_before_check_in(): void
    {
        $today = Carbon::today();

        $record = AttendanceRecord::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'work_date' => $today->toDateString(),
            'check_in_time' => $today->copy()->setTime(9, 0),
            'check_out_time' => null,
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
            'status' => AttendanceRecord::STATUS_CHECKED_IN,
            'attendance_type' => AttendanceRecord::TYPE_PRESENT,
        ]);

        $this->actingAs($this->hrManager);

        $response = $this->putJson("/api/management/attendance/{$record->id}/adjust", [
            'new_check_in' => $today->copy()->setTime(15, 0)->toDateTimeString(),
            'new_check_out' => $today->copy()->setTime(10, 0)->toDateTimeString(),
            'reason' => 'Testing invalid times.',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'وقت الانصراف يجب أن يكون بعد وقت الدخول.');

        $this->assertDatabaseMissing('attendance_adjustments', [
            'attendance_record_id' => $record->id,
        ]);
    }

    public function test_manual_adjustment_rejects_check_out_without_any_check_in(): void
    {
        $today = Carbon::today();

        // An absence-job-created record: no check-in at all.
        $record = AttendanceRecord::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'work_date' => $today->toDateString(),
            'check_in_time' => null,
            'check_out_time' => null,
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
            'status' => AttendanceRecord::STATUS_ABSENT,
            'attendance_type' => AttendanceRecord::TYPE_ABSENT,
        ]);

        $this->actingAs($this->hrManager);

        $response = $this->putJson("/api/management/attendance/{$record->id}/adjust", [
            'new_check_out' => $today->copy()->setTime(17, 0)->toDateTimeString(),
            'reason' => 'Testing check-out without check-in.',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'لا يمكن تسجيل وقت الانصراف بدون وجود تسجيل دخول لهذا اليوم.');

        $this->assertDatabaseMissing('attendance_adjustments', [
            'attendance_record_id' => $record->id,
        ]);
    }

    public function test_hr_can_manually_register_attendance_without_qr(): void
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

        $this->actingAs($this->hrManager);

        $response = $this->postJson('/api/management/attendance/register', [
            'employee_id' => $this->employee->id,
            'work_date' => $today->toDateString(),
            'check_in_time' => $today->copy()->setTime(9, 0)->toDateTimeString(),
            'check_out_time' => $today->copy()->setTime(17, 0)->toDateTimeString(),
            'reason' => 'Employee forgot phone; confirmed by department manager.',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.late_minutes', 45);
        $response->assertJsonPath('data.attendance_type', AttendanceRecord::TYPE_LATE);
        $response->assertJsonPath('data.status', AttendanceRecord::STATUS_COMPLETED);

        $this->assertDatabaseHas('attendance_records', [
            'employee_id' => $this->employee->id,
            'work_date' => $today->toDateString(),
            'status' => AttendanceRecord::STATUS_COMPLETED,
        ]);

        $this->assertDatabaseHas('attendance_adjustments', [
            'adjusted_by' => $this->hrManager->id,
            'reason' => 'Employee forgot phone; confirmed by department manager.',
        ]);
    }

    public function test_manual_register_converts_absent_record(): void
    {
        AttendancePolicy::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'work_start_time' => '08:00:00',
            'work_end_time' => '17:00:00',
            'allowed_late_minutes' => 0,
            'allowed_early_leave_minutes' => 0,
        ]);

        $today = Carbon::today();

        $absent = AttendanceRecord::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'work_date' => $today->toDateString(),
            'check_in_time' => null,
            'check_out_time' => null,
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
            'status' => AttendanceRecord::STATUS_ABSENT,
            'attendance_type' => AttendanceRecord::TYPE_ABSENT,
        ]);

        $this->actingAs($this->hrManager);

        $response = $this->postJson('/api/management/attendance/register', [
            'employee_id' => $this->employee->id,
            'work_date' => $today->toDateString(),
            'check_in_time' => $today->copy()->setTime(8, 0)->toDateTimeString(),
            'reason' => 'Was present; missed QR scan.',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.id', $absent->id);
        $response->assertJsonPath('data.status', AttendanceRecord::STATUS_CHECKED_IN);
        $response->assertJsonPath('data.attendance_type', AttendanceRecord::TYPE_PRESENT);
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

    public function test_roster_includes_all_active_employees_even_without_check_in(): void
    {
        AttendancePolicy::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'work_start_time' => '08:00:00',
            'work_end_time' => '17:00:00',
            'allowed_late_minutes' => 15,
        ]);

        $secondUser = User::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'full_name' => 'Second Employee',
            'email' => 'second@attendance.test',
            'password_hash' => bcrypt('password'),
            'role' => Role::Employee->value,
            'status' => 'active',
            'is_first_login' => false,
        ]);

        $secondEmployeeId = Str::uuid()->toString();
        Employee::create([
            'id' => $secondEmployeeId,
            'user_id' => $secondUser->id,
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'job_title' => 'QA',
            'base_salary' => 1000,
            'hire_date' => '2022-01-01',
            'employment_type' => 'full-time',
            'is_active' => true,
        ]);

        AttendanceRecord::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'work_date' => Carbon::today()->toDateString(),
            'check_in_time' => Carbon::today()->setTime(8, 0),
            'status' => AttendanceRecord::STATUS_CHECKED_IN,
            'attendance_type' => AttendanceRecord::TYPE_PRESENT,
        ]);

        Carbon::setTestNow(Carbon::today()->setTime(9, 0));

        $this->actingAs($this->hrManager);

        $response = $this->getJson('/api/management/attendance/roster?date='.Carbon::today()->toDateString());

        $response->assertOk()
            ->assertJsonPath('data.meta.total', 2);

        $statuses = collect($response->json('data.items'))->pluck('display_status', 'employee_id');
        $this->assertSame('present', $statuses[$this->employee->id]);
        $this->assertSame('not_arrived', $statuses[$secondEmployeeId]);

        Carbon::setTestNow();
    }

    public function test_roster_marks_employee_on_leave(): void
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

        LeaveRequest::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => Carbon::today()->toDateString(),
            'end_date' => Carbon::today()->toDateString(),
            'requested_value' => 1,
            'status' => 'approved',
        ]);

        $this->actingAs($this->hrManager);

        $response = $this->getJson('/api/management/attendance/roster?date='.Carbon::today()->toDateString());

        $response->assertOk()
            ->assertJsonPath('data.items.0.display_status', 'on_leave')
            ->assertJsonPath('data.items.0.leave_type_name', 'Annual')
            ->assertJsonPath('data.items.0.attendance_record_id', null);
    }

    public function test_roster_marks_absent_after_work_end_without_check_in(): void
    {
        AttendancePolicy::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'work_start_time' => '08:00:00',
            'work_end_time' => '09:00:00',
            'allowed_late_minutes' => 0,
        ]);

        Carbon::setTestNow(Carbon::today()->setTime(10, 0));

        $this->actingAs($this->hrManager);

        $response = $this->getJson('/api/management/attendance/roster?date='.Carbon::today()->toDateString());

        $response->assertOk()
            ->assertJsonPath('data.items.0.display_status', 'absent');

        Carbon::setTestNow();
    }

    public function test_roster_stats_count_all_active_employees_for_single_date(): void
    {
        AttendancePolicy::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'work_start_time' => '08:00:00',
            'work_end_time' => '17:00:00',
        ]);

        AttendanceRecord::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'work_date' => Carbon::today()->toDateString(),
            'check_in_time' => Carbon::today()->setTime(8, 30),
            'late_minutes' => 30,
            'status' => AttendanceRecord::STATUS_CHECKED_IN,
            'attendance_type' => AttendanceRecord::TYPE_LATE,
        ]);

        $this->actingAs($this->hrManager);

        $response = $this->getJson('/api/management/attendance/stats?date='.Carbon::today()->toDateString());

        $response->assertOk()
            ->assertJsonPath('data.total_employees', 1)
            ->assertJsonPath('data.late', 1)
            ->assertJsonPath('data.not_arrived', 0)
            ->assertJsonPath('data.total_records', 1);
    }

    public function test_legacy_attendance_index_still_lists_records_only(): void
    {
        AttendanceRecord::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'work_date' => Carbon::today()->toDateString(),
            'check_in_time' => now(),
            'status' => AttendanceRecord::STATUS_CHECKED_IN,
            'attendance_type' => AttendanceRecord::TYPE_PRESENT,
        ]);

        $this->actingAs($this->hrManager);

        $this->getJson('/api/management/attendance?date='.Carbon::today()->toDateString())
            ->assertOk()
            ->assertJsonPath('data.total', 1);

        $this->getJson('/api/management/attendance/roster?date='.Carbon::today()->toDateString())
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1);
    }
}
