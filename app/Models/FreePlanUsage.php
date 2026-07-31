<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class FreePlanUsage extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'id',
        'email',
        'domain',
    ];

    protected $keyType = 'string';
    public $incrementing = false;
}
