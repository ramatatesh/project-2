<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeDevice extends Model
{
    protected $fillable = [
        'id',
        'company_id',
        'employee_id',
        'device_id',
        'bound_at',
        'is_active',
        'unbound_at',
        'unbound_by',
        'unbind_reason',
        'created_at',
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $casts = [
        'bound_at' => 'datetime',
        'unbound_at' => 'datetime',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function unboundBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unbound_by');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
