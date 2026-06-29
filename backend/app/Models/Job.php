<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Job extends Model
{
    protected $fillable = [
        'customer_id',
        'handyman_id',
        'property_id',
        'status',
        'address',
        'lat',
        'lng',
        'initial_description',
        'agreed_price',
        'onsite_contact_name',
        'onsite_contact_phone',
        'skills',
    ];

    protected $casts = [
        'lat' => 'decimal:7',
        'lng' => 'decimal:7',
        'agreed_price' => 'decimal:2',
        'skills' => 'array',
        'property_id' => 'integer',
    ];

    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function handyman()
    {
        return $this->belongsTo(User::class, 'handyman_id');
    }

    public function images()
    {
        return $this->hasMany(JobImage::class);
    }
    
    public function changeOrders()
    {
        return $this->hasMany(ChangeOrder::class);
    }

    public function review()
    {
        return $this->hasOne(Review::class);
    }

    public function disputes()
    {
        return $this->hasMany(Dispute::class);
    }

    public function reports()
    {
        return $this->hasMany(Report::class);
    }
}