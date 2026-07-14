<?php

namespace App\Enums;

enum Role: string
{
    case SuperAdmin = 'super_admin';
    case GeneralManager = 'general_manager';
    case HrManager = 'hr_manager';
    case DepartmentManager = 'department_manager';
    case Employee = 'employee';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::GeneralManager => 'General Manager',
            self::HrManager => 'HR Manager',
            self::DepartmentManager => 'Department Manager',
            self::Employee => 'Employee',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function isSuperAdmin(): bool
    {
        return $this === self::SuperAdmin;
    }

    public function isTenantUser(): bool
    {
        return $this !== self::SuperAdmin;
    }
}
