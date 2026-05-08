<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContractorProfile extends Model
{
    protected $fillable = [
        'user_id',
        'business_name',
        'bio',
        'service_area',
        'phone',
        'website',
        'license_number',
        'years_experience',
        'profile_photo_path',
        'status',
        'is_public',
    ];

    protected $casts = [
        'is_public' => 'boolean',
        'years_experience' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function claims()
    {
        return $this->hasMany(ProfileClaim::class);
    }
}