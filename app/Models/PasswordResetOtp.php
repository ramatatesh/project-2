<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PasswordResetOtp extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'email',
        'otp',
        'expires_at',
        'verified'
    ];

    protected $casts = [
        'expires_at'=>'datetime',
        'verified'=>'boolean'
    ];
}
