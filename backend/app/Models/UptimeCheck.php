<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UptimeCheck extends Model
{
    protected $fillable = [
        'target_key',
        'target_name',
        'target_url',
        'status',
        'status_code',
        'response_time_ms',
        'error_message',
        'consecutive_failures',
        'alert_sent',
        'checked_at',
    ];

    protected $casts = [
        'status_code' => 'integer',
        'response_time_ms' => 'integer',
        'consecutive_failures' => 'integer',
        'alert_sent' => 'boolean',
        'checked_at' => 'datetime',
    ];
}
