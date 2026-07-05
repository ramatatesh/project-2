<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    use HasUuids;

    protected $fillable = ['id', 'company_id', 'plan_id', 'plan_type', 'monthly_price', 'start_date', 'end_date', 'status'];
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $casts = [
        'monthly_price' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function plan(): BelongsTo { return $this->belongsTo(SubscriptionPlan::class, 'plan_id'); }
    public function paymentTransactions(): HasMany { return $this->hasMany(PaymentTransaction::class); }
}
