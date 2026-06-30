<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JobMessage extends Model
{
    protected $fillable = [
        'job_id',
        'sender_user_id',
        'message',
        'message_type',
        'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    public function job()
    {
        return $this->belongsTo(Job::class);
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }
}
