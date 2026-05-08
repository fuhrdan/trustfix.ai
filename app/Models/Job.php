<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Job extends Model
{
    protected $fillable = [
        'customer_id',
        'handyman_id',
        'status',
        'address',
        'lat',
        'lng',
        'initial_description',
        'agreed_price',
    ];

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function handyman()
    {
        return $this->belongsTo(User::class, 'handyman_id');
    }

    public function changeOrders()
    {
        return $this->hasMany(ChangeOrder::class);
    }
}