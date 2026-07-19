<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EvaluationReview extends Model
{
    use HasUuids;

    protected $fillable = [
        'id',
        'company_id',
        'evaluation_cycle_id',
        'employee_id',
        'reviewer_id',
        'review_type',
        'status',
        'submitted_at',
        'due_date',
        'total_score',
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $casts = [
        'submitted_at' => 'datetime',
        'due_date' => 'date',
    ];

    public const TYPE_MANAGER = 'manager';

    public const TYPE_SELF = 'self';

    public const TYPE_PEER = 'peer';

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

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

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(EvaluationAnswer::class, 'evaluation_review_id');
    }
}
