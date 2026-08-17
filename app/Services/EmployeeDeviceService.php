<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeDevice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EmployeeDeviceService
{
    /**
     * Assert the given device may check in for this employee.
     * First successful use auto-binds the device.
     * Caller should wrap in a DB transaction when used during check-in.
     *
     * @return array{success: bool, message?: string, code?: string, device?: EmployeeDevice, bound_now?: bool}
     */
    public function assertAndBind(Employee $employee, string $deviceId): array
    {
        $deviceId = trim($deviceId);

        if ($deviceId === '') {
            return [
                'success' => false,
                'code' => 'device_required',
                'message' => 'device_id is required for attendance check-in.',
            ];
        }

        Employee::where('id', $employee->id)->lockForUpdate()->firstOrFail();

        $activeForEmployee = EmployeeDevice::query()
            ->where('employee_id', $employee->id)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first();

        $activeForDevice = EmployeeDevice::query()
            ->where('company_id', $employee->company_id)
            ->where('device_id', $deviceId)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first();

        if ($activeForDevice && $activeForDevice->employee_id !== $employee->id) {
            return [
                'success' => false,
                'code' => 'device_bound_to_other',
                'message' => 'This device is already bound to another employee. Buddy punching is not allowed.',
            ];
        }

        if ($activeForEmployee) {
            if ($activeForEmployee->device_id !== $deviceId) {
                return [
                    'success' => false,
                    'code' => 'device_mismatch',
                    'message' => 'This account is bound to a different device. Contact HR to unbind your old device before checking in from a new phone.',
                ];
            }

            return [
                'success' => true,
                'device' => $activeForEmployee,
            ];
        }

        $device = EmployeeDevice::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $employee->company_id,
            'employee_id' => $employee->id,
            'device_id' => $deviceId,
            'bound_at' => now(),
            'is_active' => true,
            'created_at' => now(),
        ]);

        return [
            'success' => true,
            'device' => $device,
            'bound_now' => true,
        ];
    }

    /**
     * Require that check-out uses the same bound device as the employee.
     *
     * @return array{success: bool, message?: string, code?: string}
     */
    public function assertMatchesBound(Employee $employee, ?string $deviceId): array
    {
        $deviceId = trim((string) $deviceId);
        $active = $this->activeDevice($employee);

        if (! $active) {
            // Legacy / edge case: checked in before binding existed.
            return ['success' => true];
        }

        if ($deviceId === '') {
            return [
                'success' => false,
                'code' => 'device_required',
                'message' => 'device_id is required for attendance check-out.',
            ];
        }

        if ($active->device_id !== $deviceId) {
            return [
                'success' => false,
                'code' => 'device_mismatch',
                'message' => 'Check-out must be performed from the device bound to this employee account.',
            ];
        }

        return ['success' => true];
    }

    public function activeDevice(Employee $employee): ?EmployeeDevice
    {
        return EmployeeDevice::query()
            ->where('employee_id', $employee->id)
            ->where('is_active', true)
            ->first();
    }

    public function unbind(Employee $employee, User $actor, ?string $reason = null): ?EmployeeDevice
    {
        return DB::transaction(function () use ($employee, $actor, $reason) {
            $active = EmployeeDevice::query()
                ->where('employee_id', $employee->id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if (! $active) {
                return null;
            }

            $active->is_active = false;
            $active->unbound_at = now();
            $active->unbound_by = $actor->id;
            $active->unbind_reason = $reason;
            $active->save();

            return $active;
        });
    }

    public function serialize(?EmployeeDevice $device): ?array
    {
        if (! $device) {
            return null;
        }

        return [
            'id' => $device->id,
            'employee_id' => $device->employee_id,
            'device_id' => $device->device_id,
            'bound_at' => optional($device->bound_at)?->toDateTimeString(),
            'is_active' => (bool) $device->is_active,
            'unbound_at' => optional($device->unbound_at)?->toDateTimeString(),
            'unbound_by' => $device->unbound_by,
            'unbind_reason' => $device->unbind_reason,
        ];
    }
}
