<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalaryAdvancePolicy extends Model
{
    use HasUuids;

    protected $fillable = [
        'id',
        'company_id',
        'max_advance_percentage',
        'max_repayment_months',
        'allow_multiple_active_advances',
    ];

    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $casts = [
        'max_advance_percentage' => 'decimal:2',
        'max_repayment_months' => 'integer',
        'allow_multiple_active_advances' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
