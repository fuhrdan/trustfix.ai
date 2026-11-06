<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartnerMemberAccount extends Model
{
    protected $fillable = [
        'partner_api_client_id',
        'user_id',
        'external_member_id',
        'membership_status',
        'last_confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'last_confirmed_at' => 'datetime',
        ];
    }

    public function partnerClient()
    {
        return $this->belongsTo(PartnerApiClient::class, 'partner_api_client_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function serviceRequests()
    {
        return $this->hasMany(PartnerServiceRequest::class);
    }
}
