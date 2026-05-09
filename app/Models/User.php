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
use Illuminate\Notifications\Notifiable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

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
        'address',
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
            'role' => $this->role,
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

    public function customerJobs()
    {
        return $this->hasMany(Job::class, 'customer_id');
    }

    public function handymanJobs()
    {
        return $this->hasMany(Job::class, 'handyman_id');
    }

    public function contractorProfile()
    {
        return $this->hasOne(ContractorProfile::class);
    }

    public function profileClaims()
    {
        return $this->hasMany(ProfileClaim::class, 'claimant_user_id');
    }

    public function reviewedProfileClaims()
    {
        return $this->hasMany(ProfileClaim::class, 'reviewed_by');
    }

    public function reviewedDocuments()
    {
        return $this->hasMany(Document::class, 'reviewed_by');
    }

    public function assignedContractorBadges()
    {
        return $this->hasMany(ContractorBadge::class, 'assigned_by');
    }

    public function customerReviews()
    {
        return $this->hasMany(Review::class, 'customer_id');
    }

    public function handymanReviews()
    {
        return $this->hasMany(Review::class, 'handyman_id');
    }

    public function moderatedReviews()
    {
        return $this->hasMany(Review::class, 'moderated_by');
    }
}