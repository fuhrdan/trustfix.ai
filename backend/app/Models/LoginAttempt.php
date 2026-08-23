<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoginAttempt extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'email',
        'ip_address',
        'user_agent',
        'successful',
        'outcome',
        'risk_level',
        'risk_score',
        'recent_ip_failures',
        'targeted_accounts',
        'created_at',
    ];

    protected $casts = [
        'successful' => 'boolean',
        'risk_score' => 'integer',
        'recent_ip_failures' => 'integer',
        'targeted_accounts' => 'integer',
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
