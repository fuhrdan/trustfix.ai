<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'request_uuid',
        'admin_user_id',
        'action',
        'resource_type',
        'resource_id',
        'http_method',
        'route_path',
        'route_name',
        'status_code',
        'ip_address',
        'user_agent',
        'summary',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'status_code' => 'integer',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function administrator()
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }
}
