<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BlockedIp extends Model
{
    protected $fillable = [
        'ip_address',
        'reason',
        'blocked_by',
        'blocked_at',
        'blocked_until',
        'active',
        'unblocked_at',
        'unblocked_by',
    ];

    protected $casts = [
        'blocked_at' => 'datetime',
        'blocked_until' => 'datetime',
        'active' => 'boolean',
        'unblocked_at' => 'datetime',
    ];

    public function administrator()
    {
        return $this->belongsTo(User::class, 'blocked_by');
    }

    public function unblockedByAdministrator()
    {
        return $this->belongsTo(User::class, 'unblocked_by');
    }

    public function isCurrentlyBlocked(): bool
    {
        return $this->active
            && ($this->blocked_until === null || $this->blocked_until->isFuture());
    }
}
