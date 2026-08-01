<?php

namespace App\Services;

use App\Models\AttendanceLocationLog;
use App\Models\AttendancePolicy;
use App\Models\AttendanceRecord;
use App\Models\AttendanceAdjustment;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\HolidayPolicy;
use App\Models\LeaveRequest;
use App\Models\User;
use Carbon\Carbon;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AttendanceService
{
    /**
     * How long (in 60-second windows) a QR token stays acceptable after being displayed.
     * 1 = the current window only; we allow the previous window too so a scan that lands
     * right on the rotation boundary (network/camera delay) isn't rejected.
     */
    private const QR_TOLERANCE_WINDOWS = 2;

    private const QR_WINDOW_SECONDS = 60;

    private const DEFAULT_ALLOWED_PERIMETER_METERS = 150;

    /*
    |--------------------------------------------------------------------------
    | QR token (stateless, time-windowed HMAC - no DB table, no extra package)
    |--------------------------------------------------------------------------
    */

    /**
     * Current QR token for a company - including the rendered QR image - meant to be displayed
     * on a kiosk/screen and scanned by employees. Rotates automatically every 60 seconds since the
     * token is derived from the current time window - nothing needs to be stored or refreshed manually.
     *
     * The image is generated entirely on the backend (PNG, base64 data URI) so that every client
     * (web, Flutter, a future kiosk screen) only ever has to *display* the same image rather than
     * generate its own QR code from the raw token.
     */
    public function currentQrToken(string $companyId, ?int $timestamp = null): array
    {
        $timestamp ??= now()->timestamp;
        $window = intdiv($timestamp, self::QR_WINDOW_SECONDS);
        $token = $this->buildQrToken($companyId, $window);

        return [
            'token' => $token,
            'qr_image' => $this->renderQrImage($token),
            'expires_in_seconds' => self::QR_WINDOW_SECONDS - ($timestamp % self::QR_WINDOW_SECONDS),
        ];
    }

    /**
     * Renders the given string as a QR code PNG and returns it as a "data:image/png;base64,..."
     * URI - directly usable as an <img src="..."> on web, or base64-decoded into raw bytes on
     * Flutter (e.g. Image.memory(base64Decode(uri.split(',').last))).
     */
    private function renderQrImage(string $token): string
    {
        $options = new QROptions([
            'version' => 5,
            'outputInterface' => QRGdImagePNG::class,
            'eccLevel' => EccLevel::L,
            'scale' => 8,
            'imageTransparent' => false,
        ]);

        return (new QRCode($options))->render($token);
    }

    public function validateQrToken(string $companyId, ?string $token): bool
    {
        if (blank($token) || ! str_contains($token, '.')) {
            return false;
        }

        [$window, $signature] = explode('.', $token, 2);

        if (! ctype_digit($window)) {
            return false;
        }

        $currentWindow = intdiv(now()->timestamp, self::QR_WINDOW_SECONDS);
        $window = (int) $window;

        // Reject future windows and anything older than the tolerance - a QR is only
        // ever valid for the window it was generated in (plus one grace window).
        if ($window > $currentWindow || $window < $currentWindow - self::QR_TOLERANCE_WINDOWS) {
            return false;
        }

        return hash_equals($this->buildQrToken($companyId, $window), $window.'.'.$signature);
    }

    private function buildQrToken(string $companyId, int $window): string
    {
        $signature = substr(hash_hmac('sha256', $companyId.'|'.$window, config('app.key')), 0, 24);

        return $window.'.'.$signature;
    }

    /*
    |--------------------------------------------------------------------------
    | GPS / distance verification
    |--------------------------------------------------------------------------
    */

    public function haversineDistanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusMeters = 6371000;

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusMeters * $c;
    }

    /*
    |--------------------------------------------------------------------------
    | Check-in / Check-out
    |--------------------------------------------------------------------------
    */

    public function checkIn(Employee $employee, array $data): array
    {
        $companyId = $employee->company_id;
        $policy = AttendancePolicy::where('company_id', $companyId)->first();
        $today = Carbon::today();
        // Prevent attendance on official company holidays.
        $isHoliday = Holiday::where('company_id', $companyId)
          ->get()
         ->contains(fn (Holiday $holiday) => $holiday->occursOn($today));

        if ($isHoliday) {
          return $this->failure('Today is an official company holiday. Attendance is not allowed.');
        }

        if (! $this->validateQrToken($companyId, $data['qr_token'] ?? null)) {
            return $this->failure('Invalid or expired QR code. Please scan the current code.');
        }

        if (AttendanceRecord::where('company_id', $companyId)
            ->where('employee_id', $employee->id)
            ->where('work_date', $today->toDateString())
            ->exists()
        ) {
            return $this->failure('You have already checked in today.');
        }

        $gpsCheck = $this->verifyGps($policy, $data);

        if (! $gpsCheck['passed']) {
            $this->logLocationAttempt(null, $gpsCheck);

            return $this->failure($gpsCheck['message']);
        }

        return DB::transaction(function () use ($employee, $companyId, $policy, $today, $data, $gpsCheck) {
            $isWorkDay = $this->isWorkDay($today, $policy);
            if (! $isWorkDay) {
             return $this->failure('Today is a weekly day off. Attendance is not allowed.');
            }
            $lateMinutes = $isWorkDay ? $this->computeLateMinutes($today, $policy) : 0;

            $record = AttendanceRecord::create([
                'id' => Str::uuid()->toString(),
                'company_id' => $companyId,
                'employee_id' => $employee->id,
                'work_date' => $today->toDateString(),
                'check_in_time' => now(),
                'check_in_lat' => $gpsCheck['latitude'],
                'check_in_lng' => $gpsCheck['longitude'],
                'check_in_device_id' => $data['device_id'] ?? null,
                'qr_token_used' => explode('.', $data['qr_token'])[0],
                'late_minutes' => $lateMinutes,
                'early_leave_minutes' => 0,
                'total_work_minutes' => null,
                'status' => AttendanceRecord::STATUS_CHECKED_IN,
                'attendance_type' => $lateMinutes > 0
                  ? AttendanceRecord::TYPE_LATE
                  : AttendanceRecord::TYPE_PRESENT,
            ]);

            if ($gpsCheck['checked']) {
                $this->logLocationAttempt($record->id, $gpsCheck);
            }

            return ['success' => true, 'record' => $record];
        });
    }

    public function checkOut(Employee $employee, array $data): array
    {
        $companyId = $employee->company_id;
        $today = Carbon::today();

        $record = AttendanceRecord::where('company_id', $companyId)
         ->where('employee_id', $employee->id)
         ->where('work_date', $today->toDateString())
         ->first();

        if (! $record || $record->status !== AttendanceRecord::STATUS_CHECKED_IN) {
            return $this->failure('You have not checked in today or your attendance is already completed.');
        }

        if ($record->check_out_time !== null) {
           return $this->failure('You have already checked out today.');
        }

        if (! $this->validateQrToken($companyId, $data['qr_token'] ?? null)) {
            return $this->failure('Invalid or expired QR code. Please scan the current code.');
        }

        $policy = AttendancePolicy::where('company_id', $companyId)->first();
        $gpsCheck = $this->verifyGps($policy, $data);

        if (! $gpsCheck['passed']) {
            $this->logLocationAttempt($record->id, $gpsCheck);

            return $this->failure($gpsCheck['message']);
        }

        return DB::transaction(function () use ($record, $policy, $today, $gpsCheck) {
            $checkOutTime = now();
            $isWorkDay = $this->isWorkDay($today, $policy);
            $earlyLeaveMinutes = $isWorkDay ? $this->computeEarlyLeaveMinutes($today, $policy, $checkOutTime) : 0;

            $record->check_out_time = $checkOutTime;
            $record->check_out_lat = $gpsCheck['latitude'];
            $record->check_out_lng = $gpsCheck['longitude'];
            $record->early_leave_minutes = $earlyLeaveMinutes;
            $record->total_work_minutes = (int) round($record->check_in_time->diffInMinutes($checkOutTime));
            $record->status = AttendanceRecord::STATUS_COMPLETED;
            $record->attendance_type = $this->classifyAttendanceType($isWorkDay, $record->late_minutes, $earlyLeaveMinutes);
            $record->save();

            if ($gpsCheck['checked']) {
                $this->logLocationAttempt($record->id, $gpsCheck);
            }

            return ['success' => true, 'record' => $record];
        });
    }

    /*
    |--------------------------------------------------------------------------
    | HR/GM manual adjustment
    |--------------------------------------------------------------------------
    */

    public function adjust(AttendanceRecord $record, User $adjuster, array $data): AttendanceRecord
    {
        return DB::transaction(function () use ($record, $adjuster, $data) {
            $oldCheckIn = $record->check_in_time;
            $oldCheckOut = $record->check_out_time;

            $newCheckIn = isset($data['new_check_in']) ? Carbon::parse($data['new_check_in']) : $oldCheckIn;
            $newCheckOut = array_key_exists('new_check_out', $data) && $data['new_check_out'] !== null
                ? Carbon::parse($data['new_check_out'])
                : $oldCheckOut;

            $policy = AttendancePolicy::where('company_id', $record->company_id)->first();
            $workDate = Carbon::parse($record->work_date);
            $isWorkDay = $this->isWorkDay($workDate, $policy);

            $record->check_in_time = $newCheckIn;
            $record->check_out_time = $newCheckOut;
            $record->late_minutes = ($isWorkDay && $newCheckIn) ? $this->computeLateMinutes($workDate, $policy, $newCheckIn) : 0;
            $record->early_leave_minutes = ($isWorkDay && $newCheckOut) ? $this->computeEarlyLeaveMinutes($workDate, $policy, $newCheckOut) : 0;
            $record->total_work_minutes = ($newCheckIn && $newCheckOut) ? (int) round($newCheckIn->diffInMinutes($newCheckOut)) : null;
            $record->status = $newCheckOut ? AttendanceRecord::STATUS_COMPLETED : AttendanceRecord::STATUS_CHECKED_IN;
            $record->attendance_type = $this->classifyAttendanceType($isWorkDay, $record->late_minutes, $record->early_leave_minutes);
            $record->save();

            AttendanceAdjustment::create([
                'id' => Str::uuid()->toString(),
                'company_id' => $record->company_id,
                'attendance_record_id' => $record->id,
                'adjusted_by' => $adjuster->id,
                'old_check_in' => $oldCheckIn,
                'new_check_in' => $newCheckIn,
                'old_check_out' => $oldCheckOut,
                'new_check_out' => $newCheckOut,
                'reason' => $data['reason'],
            ]);

            return $record;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Absence marking (used by the console command / scheduler)
    |--------------------------------------------------------------------------
    */

    public function markAbsentForDate(Carbon $date): array
    {
        $created = 0;
        $dayName = strtolower($date->format('l'));

        Company::query()->chunkById(50, function ($companies) use ($date, $dayName, &$created) {
            foreach ($companies as $company) {
                $holidayPolicy = HolidayPolicy::where('company_id', $company->id)->first();

                if ($holidayPolicy && in_array($dayName, $holidayPolicy->weekly_holidays ?? [], true)) {
                    continue; // Weekly holiday (e.g. Friday) - nobody is absent.
                }

                $isCompanyHoliday = Holiday::where('company_id', $company->id)
                    ->get()
                    ->contains(fn (Holiday $holiday) => $holiday->occursOn($date));

                if ($isCompanyHoliday) {
                    continue;
                }

                $policy = AttendancePolicy::where('company_id', $company->id)->first();

                if (! $this->isWorkDay($date, $policy)) {
                    continue;
                }

                $created += $this->markAbsentEmployeesForCompany($company->id, $date);
            }
        });

        return ['created' => $created];
    }

    private function markAbsentEmployeesForCompany(string $companyId, Carbon $date): int
    {
        $created = 0;

        Employee::where('company_id', $companyId)
            ->where('is_active', true)
            ->chunkById(100, function ($employees) use ($companyId, $date, &$created) {
                foreach ($employees as $employee) {
                    $hasRecord = AttendanceRecord::where('company_id', $companyId)
                        ->where('employee_id', $employee->id)
                        ->where('work_date', $date->toDateString())
                        ->exists();

                    if ($hasRecord) {
                        continue;
                    }

                    $hasApprovedLeave = LeaveRequest::where('employee_id', $employee->id)
                        ->where('status', 'approved')
                        ->whereDate('start_date', '<=', $date->toDateString())
                        ->whereDate('end_date', '>=', $date->toDateString())
                        ->exists();

                    if ($hasApprovedLeave) {
                        continue;
                    }

                    AttendanceRecord::create([
                        'id' => Str::uuid()->toString(),
                        'company_id' => $companyId,
                        'employee_id' => $employee->id,
                        'work_date' => $date->toDateString(),
                        'check_in_time' => null,
                        'check_out_time' => null,
                        'late_minutes' => 0,
                        'early_leave_minutes' => 0,
                        'total_work_minutes' => null,
                        'status' => AttendanceRecord::STATUS_ABSENT,
                        'attendance_type' => AttendanceRecord::TYPE_ABSENT,
                        'notes' => 'Marked absent automatically - no check-in and no approved leave for this date.',
                    ]);

                    $created++;
                }
            });

        return $created;
    }

    /*
    |--------------------------------------------------------------------------
    | Internal helpers
    |--------------------------------------------------------------------------
    */

    private function isWorkDay(Carbon $date, ?AttendancePolicy $policy): bool
    {
        if (! $policy || empty($policy->work_days)) {
            return true;
        }

        return in_array(strtolower($date->format('l')), $policy->work_days, true);
    }

    private function computeLateMinutes(Carbon $workDate, ?AttendancePolicy $policy, ?Carbon $checkInTime = null): int
    {
        if (! $policy || blank($policy->work_start_time)) {
            return 0;
        }

        $checkInTime ??= now();
        $expectedStart = Carbon::parse($workDate->toDateString().' '.$policy->work_start_time);

        if ($checkInTime->lessThanOrEqualTo($expectedStart)) {
            return 0;
        }

        $minutesLate = $expectedStart->diffInMinutes($checkInTime);
        $grace = (int) ($policy->allowed_late_minutes ?? 0);

        return max(0, $minutesLate - $grace);
    }

    private function computeEarlyLeaveMinutes(Carbon $workDate, ?AttendancePolicy $policy, Carbon $checkOutTime): int
    {
        if (! $policy || blank($policy->work_end_time)) {
            return 0;
        }

        $expectedEnd = Carbon::parse($workDate->toDateString().' '.$policy->work_end_time);

        if ($checkOutTime->greaterThanOrEqualTo($expectedEnd)) {
            return 0;
        }

        $minutesEarly = $checkOutTime->diffInMinutes($expectedEnd);
        $grace = (int) ($policy->allowed_early_leave_minutes ?? 0);

        return max(0, $minutesEarly - $grace);
    }

    private function classifyAttendanceType(bool $isWorkDay, int $lateMinutes, int $earlyLeaveMinutes): string
    {
        if (! $isWorkDay) {
            return AttendanceRecord::TYPE_OFF_DAY;
        }

        if ($lateMinutes > 0) {
            return AttendanceRecord::TYPE_LATE;
        }

        if ($earlyLeaveMinutes > 0) {
            return AttendanceRecord::TYPE_EARLY_LEAVE;
        }

        return AttendanceRecord::TYPE_PRESENT;
    }

    /**
     * @return array{passed: bool, checked: bool, message: ?string, latitude: ?float, longitude: ?float, distance: ?float, within_radius: ?bool}
     */
    private function verifyGps(?AttendancePolicy $policy, array $data): array
    {
        if (! $policy || ! $policy->enable_gps_verification) {
            return [
                'passed' => true,
                'checked' => false,
                'message' => null,
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'distance' => null,
                'within_radius' => null,
            ];
        }

        if ($policy->latitude === null || $policy->longitude === null) {
            return [
                'passed' => false,
                'checked' => false,
                'message' => 'Company location is not configured yet. Please contact HR to set up the attendance location.',
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'distance' => null,
                'within_radius' => null,
            ];
        }

        $latitude = (float) ($data['latitude'] ?? 0);
        $longitude = (float) ($data['longitude'] ?? 0);

        $distance = $this->haversineDistanceMeters(
            (float) $policy->latitude,
            (float) $policy->longitude,
            $latitude,
            $longitude
        );

        $allowedPerimeter = (int) ($policy->allowed_perimeter ?? self::DEFAULT_ALLOWED_PERIMETER_METERS);
        $withinRadius = $distance <= $allowedPerimeter;

        return [
            'passed' => $withinRadius,
            'checked' => true,
            'message' => $withinRadius ? null : 'You are outside the allowed check-in location radius.',
            'latitude' => $latitude,
            'longitude' => $longitude,
            'distance' => $distance,
            'within_radius' => $withinRadius,
        ];
    }

    private function logLocationAttempt(?string $attendanceRecordId, array $gpsCheck): void
    {
        if (! $gpsCheck['checked']) {
            return;
        }

        AttendanceLocationLog::create([
            'id' => Str::uuid()->toString(),
            'attendance_record_id' => $attendanceRecordId,
            'latitude' => $gpsCheck['latitude'],
            'longitude' => $gpsCheck['longitude'],
            'distance_from_company' => $gpsCheck['distance'] !== null ? round($gpsCheck['distance']) : null,
            'is_within_radius' => $gpsCheck['within_radius'],
            'checked_at' => now(),
        ]);
    }

    private function failure(string $message): array
    {
        return ['success' => false, 'message' => $message];
    }
}
