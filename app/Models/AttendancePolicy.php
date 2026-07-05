<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendancePolicy extends Model
{
    use HasUuids;

    protected $fillable = ['id', 'company_id', 'monthly_late_threshold_min', 'consecutive_absent_alert', 'enable_gps_verification', 'company_latitude', 'company_longitude', 'allowed_radius', 'work_start_time', 'work_end_time', 'allowed_late_minutes', 'allowed_early_leave_minutes', 'work_days', 'minimum_daily_hours', 'allows_overtime'];
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $casts = [
        'work_days' => 'array',
        'allows_overtime' => 'boolean',
    ];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
}
