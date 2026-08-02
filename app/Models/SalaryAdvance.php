<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalaryAdvance extends Model
{
    use HasUuids;

    public const STATUS_PENDING_DEPARTMENT_MANAGER = 'pending_department_manager';
    public const STATUS_PENDING_HR = 'pending_hr';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED_BY_MANAGER = 'rejected_by_manager';
    public const STATUS_REJECTED_BY_HR = 'rejected_by_hr';
    public const STATUS_PAID_OFF = 'paid_off';

    protected $fillable = [
        'id',
        'company_id',
        'employee_id',
        'requested_amount',
        'repayment_months',
        'monthly_installment',
        'reason',
        'rejection_reason',
        'status',
        'approved_by_manager_id',
        'approved_by_hr_id',
    ];

    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $casts = [
        'requested_amount' => 'decimal:2',
        'monthly_installment' => 'decimal:2',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function installments(): HasMany
    {
        return $this->hasMany(SalaryAdvanceInstallment::class);
    }

    public function approvingManager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approved_by_manager_id');
    }

    public function approvingHr(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approved_by_hr_id');
    }

    public function isActive(): bool
    {
        if (in_array($this->status, [self::STATUS_PENDING_DEPARTMENT_MANAGER, self::STATUS_PENDING_HR], true)) {
            return true;
        }

        if ($this->status === self::STATUS_APPROVED) {
            return $this->installments()->where('status', SalaryAdvanceInstallment::STATUS_PENDING)->exists();
        }

        return false;
    }
}
