<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceAdjustment extends Model
{
    protected $fillable = ['id', 'company_id', 'attendance_record_id', 'adjusted_by', 'old_check_in', 'new_check_in', 'old_check_out', 'new_check_out', 'reason'];
    protected $keyType = 'string';
    public $incrementing = false;

    // The table has no `updated_at` column - only `created_at` - so Eloquent's default
    // timestamps must be disabled, otherwise any save()/update() throws a column-not-found error.
    public $timestamps = false;

    protected $casts = [
        'old_check_in' => 'datetime',
        'new_check_in' => 'datetime',
        'old_check_out' => 'datetime',
        'new_check_out' => 'datetime',
    ];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function attendanceRecord(): BelongsTo { return $this->belongsTo(AttendanceRecord::class); }
    public function adjuster(): BelongsTo { return $this->belongsTo(User::class, 'adjusted_by'); }
}
