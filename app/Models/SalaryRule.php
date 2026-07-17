<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalaryRule extends Model
{
    use HasUuids;

    protected $fillable = ['id', 'company_id', 'rule_type', 'time_unit', 'operation', 'value_type', 'value', 'is_active'];

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function isPercent(): bool
    {
        return in_array($this->value_type, ['percent', 'percentage'], true);
    }

    public function dailyWage(float $monthlySalary): float
    {
        return $monthlySalary / 30;
    }

    public function calculate(float $monthlySalary, float $units): float
    {
        $rate = $this->isPercent() ? ($this->value / 100) : $this->value;

        return $this->dailyWage($monthlySalary) * $rate * $units;
    }
}
