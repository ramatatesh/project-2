<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkSchedule extends Model
{
    protected $fillable = ['id', 'company_id', 'work_start', 'work_end', 'grace_minutes', 'early_leave_minutes', 'min_hours_per_day', 'overtime_allowed', 'is_default'];
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
}
