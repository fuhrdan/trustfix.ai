<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PartnerApiClient extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'name',
        'slug',
        'token_hint',
        'api_token_hash',
        'scopes',
        'webhook_url',
        'webhook_secret',
        'active',
        'last_used_at',
        'created_by',
    ];

    protected $hidden = [
        'api_token_hash',
        'webhook_secret',
    ];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'webhook_secret' => 'encrypted',
            'active' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function memberAccounts()
    {
        return $this->hasMany(PartnerMemberAccount::class);
    }

    public function serviceRequests()
    {
        return $this->hasMany(PartnerServiceRequest::class);
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }
}
