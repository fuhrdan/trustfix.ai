<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContractorDocument extends Model
{
    protected $fillable = [
        'user_id',
        'document_type',
        'original_filename',
        'stored_filename',
        'mime_type',
        'file_size',
        'expires_at',
        'verification_status',
        'verified_by',
        'verified_at',
        'notes',
    ];

    protected $casts = [
        'expires_at' => 'date',
        'verified_at' => 'datetime',
    ];

    protected $appends = [
        'status',
        'verified',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function getStatusAttribute()
    {
        return match ((int) ($this->verification_status ?? 0)) {
            1 => 'approved',
            2 => 'rejected',
            default => 'pending',
        };
    }

    public function getVerifiedAttribute()
    {
        return (int) ($this->verification_status ?? 0) === 1;
    }
}
