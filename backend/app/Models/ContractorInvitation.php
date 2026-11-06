<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContractorInvitation extends Model
{
    protected $fillable = [
        'email',
        'name',
        'business_name',
        'service_area',
        'token_hash',
        'invited_by',
        'accepted_user_id',
        'expires_at',
        'sent_at',
        'accepted_at',
        'revoked_at',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'sent_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function inviter()
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function acceptedUser()
    {
        return $this->belongsTo(User::class, 'accepted_user_id');
    }

    public function isUsable(): bool
    {
        return $this->accepted_at === null
            && $this->revoked_at === null
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
