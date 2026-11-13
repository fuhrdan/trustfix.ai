<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One additive authorization capability assigned to a canonical User.
 *
 * This model does not represent a second kind of user. Identity, credentials,
 * account state, and profile ownership remain on `users`; the database unique
 * key prevents duplicate (user_id, role) assignments.
 */
class UserRole extends Model
{
    protected $fillable = [
        'user_id',
        'role',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
