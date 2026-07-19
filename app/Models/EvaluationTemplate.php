<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EvaluationTemplate extends Model
{
    use HasUuids;

    protected $fillable = ['id', 'company_id', 'name', 'description', 'is_active', 'is_archived'];

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $casts = [
        'is_active' => 'boolean',
        'is_archived' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(EvaluationTemplateQuestion::class)->orderBy('sort_order');
    }

    public function cycles(): HasMany
    {
        return $this->hasMany(EvaluationCycle::class);
    }
}
