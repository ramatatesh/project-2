<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanRequest extends Model
{
    protected $fillable = ['id', 'company_id', 'employee_id', 'amount', 'remaining_amount', 'status', 'rejection_reason', 'hr_reviewed_by', 'gm_approved_by', 'approved_at'];
    protected $keyType = 'string';
    public $incrementing = false;

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function hrReviewedBy(): BelongsTo { return $this->belongsTo(User::class, 'hr_reviewed_by'); }
    public function gmApprovedBy(): BelongsTo { return $this->belongsTo(User::class, 'gm_approved_by'); }
}
