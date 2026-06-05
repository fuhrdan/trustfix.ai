<?php

//************************************************************
//************************* PROPERTY.PHP *********************
//************************************************************

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\PropertyImage;

class Property extends Model
{
    protected $fillable =
    [
        'owner_user_id',
        'street_address',
        'city',
        'state',
        'zip',
        'county',
        'description'
    ];

    public function owner()
    {
        return $this->belongsTo(
            User::class,
            'owner_user_id'
        );
    }

    public function users()
    {
        return $this->belongsToMany(
            User::class,
            'property_users'
        );
    }
    
    public function images()
    {
        return $this->hasMany(
            PropertyImage::class
            );
    }
    
}