<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EvaluationCycle extends Model
{
    use HasUuids;

    protected $fillable = ['id', 'company_id', 'evaluation_template_id', 'name', 'start_date', 'end_date', 'status'];

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CLOSED = 'closed';

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(EvaluationTemplate::class, 'evaluation_template_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(EvaluationReview::class, 'evaluation_cycle_id');
    }

    public function scores(): HasMany
    {
        return $this->hasMany(EvaluationScore::class, 'evaluation_cycle_id');
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED || Carbon::parse($this->end_date)->endOfDay()->isPast();
    }
}
