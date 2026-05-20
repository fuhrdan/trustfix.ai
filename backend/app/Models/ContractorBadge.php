<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContractorBadge extends Model
{
    protected $fillable = [
        'contractor_profile_id',
        'badge_id',
        'assigned_by',
        'assigned_at',
        'admin_notes',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
    ];

    public function contractorProfile()
    {
        return $this->belongsTo(ContractorProfile::class);
    }

    public function badge()
    {
        return $this->belongsTo(Badge::class);
    }

    public function assignedBy()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}