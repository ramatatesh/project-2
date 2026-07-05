<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalaryAdjustment extends Model
{
    protected $fillable = ['id', 'company_id', 'salary_record_id', 'type', 'description', 'amount', 'created_by'];
    protected $keyType = 'string';
    public $incrementing = false;

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function salaryRecord(): BelongsTo { return $this->belongsTo(SalaryRecord::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
