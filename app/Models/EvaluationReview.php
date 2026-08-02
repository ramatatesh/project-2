<?php

namespace App\Models;

use Carbon\Carbon;
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

    public const STATUS_EXPIRED = 'expired';

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

    /**
     * True when the review deadline (due_date, or cycle end_date as fallback)
     * has fully passed.
     */
    public function isPastDue(): bool
    {
        $due = $this->due_date;

        if (! $due) {
            $due = $this->relationLoaded('cycle')
                ? $this->cycle?->end_date
                : $this->cycle()->value('end_date');
        }

        if (! $due) {
            return false;
        }

        return Carbon::parse($due)->endOfDay()->isPast();
    }
}
