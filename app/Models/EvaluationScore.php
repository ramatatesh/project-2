<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvaluationScore extends Model
{
    use HasUuids;

    protected $fillable = [
        'id',
        'company_id',
        'evaluation_cycle_id',
        'employee_id',
        'manager_score',
        'self_score',
        'peer_score',
        'final_score',
        'status',
        'finalized_by',
        'finalized_at',
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $casts = [
        'manager_score' => 'decimal:2',
        'self_score' => 'decimal:2',
        'peer_score' => 'decimal:2',
        'final_score' => 'decimal:2',
        'finalized_at' => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_FINALIZED = 'finalized';

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(EvaluationCycle::class, 'evaluation_cycle_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }
}
