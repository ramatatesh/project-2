<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    protected $fillable = ['id', 'company_id', 'actor_id', 'actor_role', 'action_type', 'target_table', 'target_id', 'old_values', 'new_values'];
    protected $casts = ['old_values' => 'json', 'new_values' => 'json'];
    protected $keyType = 'string';
    public $incrementing = false;

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_id'); }
}
