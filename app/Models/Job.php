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

    protected $casts = [
        'lat' => 'decimal:7',
        'lng' => 'decimal:7',
        'agreed_price' => 'decimal:2',
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