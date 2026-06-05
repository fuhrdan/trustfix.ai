<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PropertyImage extends Model
{
    protected $fillable = [
        'property_id',
        'image_path',
        'timestamps'
    ];

    public function job()
    {
        return $this->belongsTo(Property::class);
    }
}
