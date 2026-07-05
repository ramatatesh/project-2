<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalaryRecord extends Model
{
    protected $fillable = [
        'id', 'company_id', 'employee_id', 'month', 'year', 'base_salary', 'overtime_amount',
        'bonus_amount', 'late_deduction', 'absent_deduction', 'loan_deduction',
        'manual_bonus', 'manual_deduction', 'net_salary', 'status', 'closed_by', 'closed_at'
    ];
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function closer(): BelongsTo { return $this->belongsTo(User::class, 'closed_by'); }
    public function salaryAdjustments(): HasMany { return $this->hasMany(SalaryAdjustment::class); }
}
