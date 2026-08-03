<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalaryRecord extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PAID = 'paid';
    /** @deprecated use STATUS_PAID — kept for older rows */
    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'id',
        'company_id',
        'employee_id',
        'month',
        'year',
        'base_salary',
        'overtime_amount',
        'bonus_amount',
        'late_deduction',
        'absent_deduction',
        'loan_deduction',
        'manual_bonus',
        'manual_deduction',
        'net_salary',
        'status',
        'closed_by',
        'closed_at',
    ];

    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $casts = [
        'base_salary' => 'decimal:2',
        'overtime_amount' => 'decimal:2',
        'bonus_amount' => 'decimal:2',
        'late_deduction' => 'decimal:2',
        'absent_deduction' => 'decimal:2',
        'loan_deduction' => 'decimal:2',
        'manual_bonus' => 'decimal:2',
        'manual_deduction' => 'decimal:2',
        'net_salary' => 'decimal:2',
        'closed_at' => 'datetime',
        'month' => 'integer',
        'year' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function salaryAdjustments(): HasMany
    {
        return $this->hasMany(SalaryAdjustment::class);
    }
}
