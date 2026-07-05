<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OvertimeRequest extends Model
{
    protected $fillable = [
        'id', 'company_id', 'employee_id', 'request_date', 'hours_requested', 'reason',
        'status', 'review_notes', 'dept_manager_approval', 'dept_approved_at',
        'hours_approved', 'hr_registered_by', 'hr_registered_at'
    ];
    protected $keyType = 'string';
    public $incrementing = false;

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function deptManager(): BelongsTo { return $this->belongsTo(User::class, 'dept_manager_approval'); }
    public function hrManager(): BelongsTo { return $this->belongsTo(User::class, 'hr_registered_by'); }
}
