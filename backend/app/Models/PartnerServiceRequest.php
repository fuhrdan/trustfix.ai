<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartnerServiceRequest extends Model
{
    protected $fillable = [
        'partner_api_client_id',
        'partner_member_account_id',
        'job_id',
        'external_request_id',
        'scheduled_for',
        'scheduling_reference',
        'last_status_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'last_status_at' => 'datetime',
        ];
    }

    public function partnerClient()
    {
        return $this->belongsTo(PartnerApiClient::class, 'partner_api_client_id');
    }

    public function memberAccount()
    {
        return $this->belongsTo(PartnerMemberAccount::class, 'partner_member_account_id');
    }

    public function job()
    {
        return $this->belongsTo(Job::class);
    }
}
