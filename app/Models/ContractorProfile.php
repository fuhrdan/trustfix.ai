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

    public function contractorBadges()
    {
        return $this->hasMany(ContractorBadge::class);
    }

    public function badges()
    {
        return $this->belongsToMany(Badge::class, 'contractor_badges')
            ->withPivot(['assigned_by', 'assigned_at', 'admin_notes'])
            ->withTimestamps();
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    public function visibleReviews()
    {
        return $this->hasMany(Review::class)->where('is_visible', true);
    }
}