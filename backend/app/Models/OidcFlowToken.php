<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class OidcFlowToken extends Model
{
    protected $fillable = [
        'token_hash',
        'purpose',
        'user_id',
        'provider',
        'issuer',
        'subject',
        'email',
        'name',
        'email_verified',
        'claims',
        'expires_at',
        'consumed_at',
    ];

    protected $hidden = [
        'token_hash',
        'claims',
    ];

    protected function casts(): array
    {
        return [
            'email_verified' => 'boolean',
            'claims' => 'encrypted:array',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Store a short-lived, one-time browser handoff token. Only the SHA-256
     * digest is persisted so a database export cannot be replayed.
     *
     * @return string The plaintext token, shown only to the caller once.
     */
    public static function issue(array $attributes): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');

        static::create(array_merge($attributes, [
            'token_hash' => self::hash($token),
        ]));

        return $token;
    }

    public static function consume(string $token, string $purpose): ?self
    {
        return DB::transaction(function () use ($token, $purpose) {
            $record = static::query()
                ->where('token_hash', self::hash($token))
                ->where('purpose', $purpose)
                ->lockForUpdate()
                ->first();

            if (
                ! $record
                || $record->consumed_at !== null
                || ! $record->expires_at
                || $record->expires_at->isPast()
            ) {
                return null;
            }

            $record->forceFill(['consumed_at' => now()])->save();

            return $record;
        });
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
