<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProfileClaim extends Model
{
    protected $fillable = [
        'contractor_profile_id',
        'claimant_user_id',
        'business_email',
        'business_phone',
        'proof_document_path',
        'message',
        'status',
        'reviewed_by',
        'reviewed_at',
        'admin_notes',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    public function contractorProfile()
    {
        return $this->belongsTo(ContractorProfile::class);
    }

    public function claimant()
    {
        return $this->belongsTo(User::class, 'claimant_user_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}