<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContractorEmployee extends Model
{
    protected $fillable = [
        'contractor_profile_id',
        'user_id',
        'name',
        'email',
        'phone',
        'role',
        'status',
    ];

    public function contractorProfile()
    {
        return $this->belongsTo(ContractorProfile::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
