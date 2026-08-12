<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperationRun extends Model
{
    protected $fillable = [
        'operation',
        'status',
        'started_at',
        'finished_at',
        'duration_ms',
        'summary',
        'details',
        'artifact_disk',
        'artifact_path',
        'artifact_size_bytes',
        'artifact_sha256',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'details' => 'array',
        'duration_ms' => 'integer',
        'artifact_size_bytes' => 'integer',
    ];
}
