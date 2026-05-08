<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Badge extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function contractorBadges()
    {
        return $this->hasMany(ContractorBadge::class);
    }

    public function contractorProfiles()
    {
        return $this->belongsToMany(ContractorProfile::class, 'contractor_badges')
            ->withPivot(['assigned_by', 'assigned_at', 'admin_notes'])
            ->withTimestamps();
    }
}