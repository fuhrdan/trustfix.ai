<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EstimatePricingProfile extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'hourly_wage',
        'payroll_burden_percent',
        'insurance_percent',
        'tools_percent',
        'material_markup_percent',
        'travel_flat_fee',
        'overhead_percent',
        'profit_percent',
        'uncertainty_percent',
        'active',
    ];

    protected $casts = [
        'hourly_wage' => 'decimal:2',
        'payroll_burden_percent' => 'decimal:2',
        'insurance_percent' => 'decimal:2',
        'tools_percent' => 'decimal:2',
        'material_markup_percent' => 'decimal:2',
        'travel_flat_fee' => 'decimal:2',
        'overhead_percent' => 'decimal:2',
        'profit_percent' => 'decimal:2',
        'uncertainty_percent' => 'decimal:2',
        'active' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
