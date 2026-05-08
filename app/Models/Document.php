<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    protected $fillable = [
        'handyman_id',
        'file_path',
        'type',
        'verified',
    ];

    protected $casts = [
        'verified' => 'boolean',
    ];

    public function handyman()
    {
        return $this->belongsTo(User::class, 'handyman_id');
    }
}