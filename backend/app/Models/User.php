<?php
//*****************************************************************************
//** A user signs in where the soft lights glow
//** Leaves quiet traces in the codes gentle flow.
//** Each click a story the system will hold.
//** A heartbeat of data in logic and soul. -Dan
//*****************************************************************************

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
// use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;
use App\Models\Property;

class User extends Authenticatable implements JWTSubject, MustVerifyEmailContract
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
        'username',
        'password',
        'role',
        'company_id',
        'phone',
        'address',
        'lat',
        'lng',
        'account_status',
        'suspended_at',
        'suspended_by',
        'suspension_reason',
        'last_login_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
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
            'suspended_at' => 'datetime',
            'last_login_at' => 'datetime',
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

    public function sendPasswordResetNotification($token): void
    {
        app(\App\Services\LifecycleNotificationService::class)
            ->passwordReset($this, (string) $token);
    }

    public function ownedProperties()
    {
        return $this->hasMany(
            Property::class,
            'owner_user_id'
        );
    }

    public function properties()
    {
        return $this->belongsToMany(
            Property::class,
            'property_users'
        );
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

    public function contractorDocuments()
    {
        return $this->hasMany(ContractorDocument::class);
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

    public function reportsMade()
    {
        return $this->hasMany(Report::class, 'reporter_id');
    }

    public function reportsReceived()
    {
        return $this->hasMany(Report::class, 'reported_user_id');
    }

    public function reviewedReports()
    {
        return $this->hasMany(Report::class, 'reviewed_by');
    }

    public function disputesOpened()
    {
        return $this->hasMany(Dispute::class, 'opened_by');
    }

    public function disputesResolved()
    {
        return $this->hasMany(Dispute::class, 'resolved_by');
    }

    public function suspendedUsers()
    {
        return $this->hasMany(User::class, 'suspended_by');
    }

    public function suspendedBy()
    {
        return $this->belongsTo(User::class, 'suspended_by');
    }

    public function estimatePricingProfile()
    {
        return $this->hasOne(EstimatePricingProfile::class);
    }
}
