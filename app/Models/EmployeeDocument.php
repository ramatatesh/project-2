<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeDocument extends Model
{
    use HasUuids;

    protected $fillable = [
        'id',
        'employee_id',
        'profile_image_path',
        'identity_image_path',
        'university_certificate_path',
    ];

    protected $keyType = 'string';
    public $incrementing = false;

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
