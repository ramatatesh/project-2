<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    use HasUuids;

    public const TYPE_EVALUATION_ASSIGNED = 'evaluation_assigned';

    public const CHANNEL_PUSH = 'push';

    protected $fillable = [
        'id',
        'company_id',
        'user_id',
        'type',
        'title',
        'body',
        'related_id',
        'related_table',
        'is_read',
        'delivery_channel',
        'push_sent',
        'push_sent_at',
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'is_read' => 'boolean',
            'push_sent' => 'boolean',
            'push_sent_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
