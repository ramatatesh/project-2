<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OvertimeRequest extends Model
{
    public const STATUS_PENDING_DEPARTMENT_MANAGER = 'pending_department_manager';
    public const STATUS_PENDING_HR = 'pending_hr';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED_BY_MANAGER = 'rejected_by_manager';
    public const STATUS_REJECTED_BY_HR = 'rejected_by_hr';

    public const DURATION_HOUR = 'hour';
    public const DURATION_DAY = 'day';

    protected $fillable = [
        'id',
        'company_id',
        'employee_id',
        'request_date',
        'duration_type',
        'hours_requested',
        'reason',
        'status',
        'review_notes',
        'rejection_reason',
        'dept_manager_approval',
        'dept_approved_at',
        'hours_approved',
        'calculated_amount',
        'hr_registered_by',
        'hr_registered_at',
    ];

    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $casts = [
        'request_date' => 'date',
        'dept_approved_at' => 'datetime',
        'hr_registered_at' => 'datetime',
        'calculated_amount' => 'decimal:2',
        'hours_requested' => 'integer',
        'hours_approved' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function deptManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dept_manager_approval');
    }

    public function hrManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hr_registered_by');
    }

    public function approvedUnits(): int
    {
        return (int) ($this->hours_approved ?? $this->hours_requested);
    }
}
