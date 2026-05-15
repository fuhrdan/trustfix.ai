<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChangeOrder extends Model
{
    protected $fillable = [
        'job_id',
        'requested_by',
        'description',
        'price_delta',
        'status',
    ];

    public function job()
    {
        return $this->belongsTo(Job::class);
    }
}