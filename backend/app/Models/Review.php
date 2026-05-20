<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Review extends Model
{
    protected $fillable = [
        'job_id',
        'customer_id',
        'handyman_id',
        'contractor_profile_id',
        'rating',
        'comment',
        'is_visible',
        'moderated_by',
        'moderated_at',
        'admin_notes',
    ];

    protected $casts = [
        'is_visible' => 'boolean',
        'moderated_at' => 'datetime',
    ];

    public function job()
    {
        return $this->belongsTo(Job::class);
    }

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function handyman()
    {
        return $this->belongsTo(User::class, 'handyman_id');
    }

    public function contractorProfile()
    {
        return $this->belongsTo(ContractorProfile::class);
    }

    public function moderator()
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }
}