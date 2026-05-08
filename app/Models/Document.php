<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    protected $fillable = [
        'handyman_id',
        'file_path',
        'type',
        'status',
        'verified',
        'reviewed_by',
        'reviewed_at',
        'admin_notes',
    ];

    protected $casts = [
        'verified' => 'boolean',
        'reviewed_at' => 'datetime',
    ];

    public function handyman()
    {
        return $this->belongsTo(User::class, 'handyman_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}