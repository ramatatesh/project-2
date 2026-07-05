<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceLocationLog extends Model
{
    protected $fillable = ['id', 'attendance_record_id', 'latitude', 'longitude', 'distance_from_company', 'is_within_radius', 'checked_at'];
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    public function attendanceRecord(): BelongsTo { return $this->belongsTo(AttendanceRecord::class); }
}
