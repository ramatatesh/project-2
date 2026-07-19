<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvaluationTemplateQuestion extends Model
{
    use HasUuids;

    protected $fillable = ['id', 'evaluation_template_id', 'question', 'response_type', 'sort_order', 'weight'];

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $casts = [
        'weight' => 'decimal:2',
        'sort_order' => 'integer',
    ];

    public const RESPONSE_TYPE_RATING = 'rating';

    public const RESPONSE_TYPE_TEXT = 'text';

    public function template(): BelongsTo
    {
        return $this->belongsTo(EvaluationTemplate::class, 'evaluation_template_id');
    }
}
