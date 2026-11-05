<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OidcIdentity extends Model
{
    protected $fillable = [
        'user_id',
        'provider',
        'issuer',
        'issuer_hash',
        'subject',
        'subject_hash',
        'last_seen_email',
        'last_login_at',
    ];

    protected function casts(): array
    {
        return [
            'last_login_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function issuerHash(string $issuer): string
    {
        return hash('sha256', $issuer);
    }

    public static function subjectHash(string $subject): string
    {
        return hash('sha256', $subject);
    }
}
