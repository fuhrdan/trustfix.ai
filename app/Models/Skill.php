<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Skill extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'name',
        'category',
    ];

    public function handymanSkills()
    {
        return $this->hasMany(HandymanSkill::class);
    }
}