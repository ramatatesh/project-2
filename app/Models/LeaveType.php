<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveType extends Model
{
    protected $fillable = ['id', 'company_id', 'name', 'allocation_value', 'allocation_unit', 'is_paid', 'requires_proof', 'is_active'];
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function leaveBalances(): HasMany { return $this->hasMany(LeaveBalance::class); }
    public function leaveRequests(): HasMany { return $this->hasMany(LeaveRequest::class); }
}
