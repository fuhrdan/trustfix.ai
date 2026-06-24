<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContractorProfile extends Model
{
    protected $fillable = [
        'user_id',
        'business_name',
        'business_address',
        'business_phone',
        'bio',
        'service_area',
        'emergency_availability',
        'phone',
        'website',
        'year_established',
        'business_type',
        'license_number',
        'state_license',
        'local_license',
        'sales_tax_license',
        'license_expiration_date',
        'coi_path',
        'insurance_expiration_date',
        'surety_bond_path',
        'service_agreement',
        'background_check_status',
        'is_verified',
        'profile_photo_path',
        'status',
        'is_public',
    ];

    protected $casts = [
        'is_public' => 'boolean',
        'emergency_availability' => 'boolean',
        'is_verified' => 'boolean',
        'year_established' => 'integer',
        'years_experience' => 'integer',
        'license_expiration_date' => 'date',
        'insurance_expiration_date' => 'date',
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

    public function employees()
    {
        return $this->hasMany(ContractorEmployee::class);
    }

    public function reports()
    {
        return $this->hasMany(Report::class);
    }
}