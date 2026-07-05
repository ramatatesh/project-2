<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalaryRule extends Model
{
    protected $fillable = ['id', 'company_id', 'rule_type', 'time_unit', 'operation', 'value_type', 'value', 'is_active'];
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
}
