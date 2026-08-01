<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalaryAdvanceInstallment extends Model
{
    use HasUuids;

    protected $fillable = ['id', 'salary_advance_id', 'due_date', 'amount', 'status'];
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $casts = [
        'due_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function salaryAdvance(): BelongsTo
    {
        return $this->belongsTo(SalaryAdvance::class);
    }
}
