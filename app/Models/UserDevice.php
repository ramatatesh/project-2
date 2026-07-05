<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserDevice extends Model
{
    protected $fillable = ['id', 'user_id', 'fcm_token', 'device_name', 'platform', 'is_active'];
    protected $keyType = 'string';
    public $incrementing = false;

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
