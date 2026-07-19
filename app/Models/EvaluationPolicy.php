<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvaluationPolicy extends Model
{
    use HasUuids;

    protected $fillable = [
        'id',
        'company_id',
        'apply_review_to_salary',
        'excellent_bonus_percent',
        'good_bonus_percent',
        'poor_deduction_percent',
        'manager_weight',
        'self_weight',
        'peer_weight',
        'peer_reviews_count',
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $casts = [
        'apply_review_to_salary' => 'boolean',
        'excellent_bonus_percent' => 'decimal:2',
        'good_bonus_percent' => 'decimal:2',
        'poor_deduction_percent' => 'decimal:2',
        'manager_weight' => 'decimal:2',
        'self_weight' => 'decimal:2',
        'peer_weight' => 'decimal:2',
        'peer_reviews_count' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
