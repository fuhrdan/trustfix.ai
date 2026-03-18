<?php
//*****************************************************************************
//** A user signs in where the soft lights glow
//** Leaves quiet traces in the codes gentle flow.
//** Each click a story the system will hold.
//** A heartbeat of data in logic and soul. -Dan
//*****************************************************************************
namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
// use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
	'role',
	'company_id',
	'phone',
	'address'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function getJWTIdentifier()
    {
	return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
	return [
	    'role' => $this->role
	];
    }

    public function skills()
    {
	return $this->hasMany(HandymanSkill::class, 'handyman_id');
    }

    public function documents()
    {
	return $this->hasMany(Document::class, 'handyman_id');
    }
}

