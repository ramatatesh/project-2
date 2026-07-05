<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromotionRequest extends Model
{
    protected $fillable = ['id', 'company_id', 'employee_id', 'proposed_by', 'current_job_title', 'proposed_job_title', 'current_salary', 'proposed_salary', 'justification', 'status', 'hr_reviewed_by', 'gm_approved_by', 'approved_at'];
    protected $keyType = 'string';
    public $incrementing = false;

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function proposedBy(): BelongsTo { return $this->belongsTo(User::class, 'proposed_by'); }
    public function hrReviewedBy(): BelongsTo { return $this->belongsTo(User::class, 'hr_reviewed_by'); }
    public function gmApprovedBy(): BelongsTo { return $this->belongsTo(User::class, 'gm_approved_by'); }
}
