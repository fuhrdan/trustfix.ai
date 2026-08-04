<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JobEstimateRevision extends Model
{
    protected $fillable = [
        'job_estimate_id',
        'user_id',
        'action',
        'snapshot',
    ];

    protected $casts = [
        'snapshot' => 'array',
    ];

    public function estimate()
    {
        return $this->belongsTo(JobEstimate::class, 'job_estimate_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
