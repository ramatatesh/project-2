<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeAssistantSession extends Model
{
    use HasUuids;

    protected $fillable = [
        'id',
        'user_id',
        'employee_id',
        'company_id',
        'title',
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(EmployeeAssistantMessage::class, 'employee_assistant_session_id')
            ->orderBy('created_at')
            ->orderBy('id');
    }
}
