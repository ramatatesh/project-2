<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use HasUuids;

    protected $fillable = ['id', 'name', 'address', 'phone', 'email', 'domain', 'payroll_currency', 'status'];
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    public function subscriptions(): HasMany { return $this->hasMany(Subscription::class); }
    public function paymentTransactions(): HasMany { return $this->hasMany(PaymentTransaction::class); }
    public function leaveTypes(): HasMany { return $this->hasMany(LeaveType::class); }
    public function salaryRules(): HasMany { return $this->hasMany(SalaryRule::class); }
    public function holidays(): HasMany { return $this->hasMany(Holiday::class); }
    public function holidayPolicy(): HasMany { return $this->hasMany(HolidayPolicy::class); }
    public function evaluationPolicy(): HasMany { return $this->hasMany(EvaluationPolicy::class); }
    public function departments(): HasMany { return $this->hasMany(Department::class); }
    public function users(): HasMany { return $this->hasMany(User::class); }
    public function employees(): HasMany { return $this->hasMany(Employee::class); }
}
