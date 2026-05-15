<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HandymanSkill extends Model
{
    protected $fillable = [
        'handyman_id',
        'skill_id',
        'proficiency_level',
    ];

    public function handyman()
    {
        return $this->belongsTo(User::class, 'handyman_id');
    }

    public function skill()
    {
        return $this->belongsTo(Skill::class, 'skill_id');
    }
}