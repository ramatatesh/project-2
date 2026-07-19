<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvaluationAnswer extends Model
{
    use HasUuids;

    protected $fillable = [
        'id',
        'evaluation_review_id',
        'evaluation_template_question_id',
        'rating',
        'comment',
        'hr_score',
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $casts = [
        'rating' => 'integer',
        'hr_score' => 'integer',
    ];

    public function review(): BelongsTo
    {
        return $this->belongsTo(EvaluationReview::class, 'evaluation_review_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(EvaluationTemplateQuestion::class, 'evaluation_template_question_id');
    }
}
