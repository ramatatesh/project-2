<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeAssistantMessage extends Model
{
    use HasUuids;

    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    protected $fillable = [
        'id',
        'employee_assistant_session_id',
        'role',
        'message',
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    public function session(): BelongsTo
    {
        return $this->belongsTo(EmployeeAssistantSession::class, 'employee_assistant_session_id');
    }
}
