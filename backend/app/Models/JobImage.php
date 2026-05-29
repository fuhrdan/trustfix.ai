<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JobImage extends Model
{
    protected $fillable = [
        'job_id',
        'image_path'
    ];

    public function job()
    {
        return $this->belongsTo(Job::class);
    }
}
