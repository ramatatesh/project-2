<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerformanceReview extends Model
{
    protected $fillable = ['id', 'company_id', 'employee_id', 'reviewer_id', 'review_period', 'period_start', 'period_end', 'score', 'notes', 'recommend_for_promotion', 'status', 'hr_reviewed_by', 'hr_reviewed_at'];
    protected $keyType = 'string';
    public $incrementing = false;

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewer_id'); }
    public function hrReviewedBy(): BelongsTo { return $this->belongsTo(User::class, 'hr_reviewed_by'); }
}
