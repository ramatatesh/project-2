<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveRequest extends Model
{
    protected $fillable = ['id', 'company_id', 'employee_id', 'leave_type_id', 'start_date', 'end_date', 'start_time', 'end_time', 'requested_value', 'attachment_url', 'reason', 'status', 'rejection_reason', 'reviewed_by', 'reviewed_at'];
    protected $keyType = 'string';
    public $incrementing = false;

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'requested_value' => 'decimal:2',
        'reviewed_at' => 'datetime',
    ];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function leaveType(): BelongsTo { return $this->belongsTo(LeaveType::class); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }
}
