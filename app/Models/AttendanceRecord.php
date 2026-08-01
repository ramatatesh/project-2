<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceRecord extends Model
{
    // Lifecycle state of the record itself.
    public const STATUS_CHECKED_IN = 'checked_in';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_ABSENT = 'absent';

    // HR classification of the day.
    public const TYPE_PRESENT = 'present';
    public const TYPE_LATE = 'late';
    public const TYPE_EARLY_LEAVE = 'early_leave';
    public const TYPE_ABSENT = 'absent';
    public const TYPE_OFF_DAY = 'off_day';

    protected $fillable = [
        'id', 'company_id', 'employee_id', 'work_date', 'check_in_time', 'check_out_time',
        'check_in_lat', 'check_in_lng', 'check_out_lat', 'check_out_lng',
        'check_in_device_id', 'qr_token_used', 'late_minutes', 'early_leave_minutes',
        'total_work_minutes', 'status', 'attendance_type', 'notes'
    ];
    protected $keyType = 'string';
    public $incrementing = false;

    // The table has no `updated_at` column - only `created_at` - so Eloquent's default
    // timestamps must be disabled, otherwise any save()/update() throws a column-not-found error.
    public $timestamps = false;

    protected $casts = [
        'work_date' => 'date',
        'check_in_time' => 'datetime',
        'check_out_time' => 'datetime',
        'check_in_lat' => 'decimal:8',
        'check_in_lng' => 'decimal:8',
        'check_out_lat' => 'decimal:8',
        'check_out_lng' => 'decimal:8',
        'late_minutes' => 'integer',
        'early_leave_minutes' => 'integer',
        'total_work_minutes' => 'integer',
    ];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function adjustments(): HasMany { return $this->hasMany(AttendanceAdjustment::class); }
    public function locationLogs(): HasMany { return $this->hasMany(AttendanceLocationLog::class); }
}
