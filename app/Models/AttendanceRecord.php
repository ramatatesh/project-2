<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceRecord extends Model
{
    protected $fillable = [
        'id', 'company_id', 'employee_id', 'work_date', 'check_in_time', 'check_out_time',
        'check_in_lat', 'check_in_lng', 'check_out_lat', 'check_out_lng',
        'check_in_device_id', 'qr_token_used', 'late_minutes', 'early_leave_minutes',
        'total_work_minutes', 'status', 'attendance_type', 'notes'
    ];
    protected $keyType = 'string';
    public $incrementing = false;

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function adjustments(): HasMany { return $this->hasMany(AttendanceAdjustment::class); }
    public function locationLogs(): HasMany { return $this->hasMany(AttendanceLocationLog::class); }
}
