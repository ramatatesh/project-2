<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PaymentTransaction extends Model
{
    use HasFactory;


    // إعدادات الـ UUID لضمان عدم التعامل مع الحقل كـ Integer تلقائي
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id',
        'company_id',
        'subscription_id',
        'amount',
        'gateway',
        'transaction_reference',
        'status',
    ];

    /**
     * توليد معرف UUID تلقائياً عند إنشاء معاملة دفع جديدة
     */
    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = Str::uuid()->toString();
            }
        });
    }

    /**
     * علاقة المعاملة بالشركة (كل معاملة دفع تنتمي لشركة واحدة)
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * علاقة المعاملة بالاشتراك (كل معاملة دفع قد ترتبط باشتراك محدد)
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }


}
