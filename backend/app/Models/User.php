<?php

// *****************************************************************************
// ** A user signs in where the soft lights glow
// ** Leaves quiet traces in the codes gentle flow.
// ** Each click a story the system will hold.
// ** A heartbeat of data in logic and soul. -Dan
// *****************************************************************************

namespace App\Models;

use App\Services\LifecycleNotificationService;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Builder;
// use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject, MustVerifyEmailContract
{
    public const ROLES = [
        'customer',
        'handyman',
        'company',
        'admin',
    ];

    /** @use HasFactory<UserFactory> */
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
        'role_assignments',
    ];

    protected $appends = [
        'roles',
    ];

    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            if (! in_array($user->role, self::ROLES, true)) {
                $user->role = 'customer';
            }
        });

        static::created(function (User $user): void {
            if (! $user->roleAssignmentsTableExists()) {
                return;
            }

            $user->roleAssignments()->firstOrCreate([
                'role' => $user->role,
            ]);
            $user->load('roleAssignments');
        });
    }

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
            'roles' => $this->roleNames(),
        ];
    }

    public function roleAssignments(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    public function getRolesAttribute(): array
    {
        return $this->roleNames();
    }

    public function roleNames(): array
    {
        $assigned = [];

        if ($this->roleAssignmentsTableExists()) {
            $assigned = $this->relationLoaded('roleAssignments')
                ? $this->roleAssignments->pluck('role')->all()
                : $this->roleAssignments()->pluck('role')->all();
        }

        if (in_array($this->role, self::ROLES, true)) {
            $assigned[] = $this->role;
        }

        if ($assigned === []) {
            return [];
        }

        return self::normalizeRoles($assigned);
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roleNames(), true);
    }

    public function hasAnyRole(array|string $roles): bool
    {
        return array_intersect(
            self::normalizeRoles((array) $roles),
            $this->roleNames()
        ) !== [];
    }

    public function grantRole(string $role, bool $makePrimary = false): self
    {
        $role = self::normalizeRoles([$role])[0];

        if (! $this->roleAssignmentsTableExists()) {
            if ($this->role === $role && ! $makePrimary) {
                return $this;
            }

            throw new \LogicException(
                'The user_roles table is required to grant an additional role.'
            );
        }

        DB::transaction(function () use ($role, $makePrimary): void {
            $this->roleAssignments()->firstOrCreate(['role' => $role]);

            if ($makePrimary && $this->role !== $role) {
                $this->forceFill(['role' => $role])->saveQuietly();
            }
        });

        $this->load('roleAssignments');

        return $this;
    }

    public function syncRoles(array $roles, ?string $primaryRole = null): self
    {
        $roles = self::normalizeRoles($roles);

        if (! $this->roleAssignmentsTableExists()) {
            throw new \LogicException(
                'The user_roles table is required to synchronize roles.'
            );
        }

        $primaryRole ??= in_array($this->role, $roles, true)
            ? $this->role
            : $roles[0];

        if (! in_array($primaryRole, $roles, true)) {
            throw new \InvalidArgumentException(
                'The primary role must be one of the assigned roles.'
            );
        }

        DB::transaction(function () use ($roles, $primaryRole): void {
            $this->roleAssignments()
                ->whereNotIn('role', $roles)
                ->delete();

            foreach ($roles as $role) {
                $this->roleAssignments()->firstOrCreate(['role' => $role]);
            }

            if ($this->role !== $primaryRole) {
                $this->forceFill(['role' => $primaryRole])->saveQuietly();
            }
        });

        $this->load('roleAssignments');

        return $this;
    }

    public function scopeWithRole(Builder $query, string $role): Builder
    {
        $role = self::normalizeRoles([$role])[0];

        if (! $query->getModel()->roleAssignmentsTableExists()) {
            return $query->where('role', $role);
        }

        return $query->where(function (Builder $roleQuery) use ($role): void {
            $roleQuery->where('role', $role)
                ->orWhereHas('roleAssignments', function (Builder $assignmentQuery) use ($role): void {
                    $assignmentQuery->where('role', $role);
                });
        });
    }

    private function roleAssignmentsTableExists(): bool
    {
        return $this->getConnection()
            ->getSchemaBuilder()
            ->hasTable('user_roles');
    }

    public static function normalizeRoles(array $roles): array
    {
        $requested = array_values(array_unique(array_map(
            static fn ($role): string => strtolower(trim((string) $role)),
            $roles
        )));
        $normalized = array_values(array_filter(
            self::ROLES,
            static fn (string $role): bool => in_array($role, $requested, true)
        ));

        if ($normalized === [] || count($normalized) !== count($requested)) {
            throw new \InvalidArgumentException('One or more user roles are invalid.');
        }

        return $normalized;
    }

    public function sendPasswordResetNotification($token): void
    {
        app(LifecycleNotificationService::class)
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

    public function supportCases()
    {
        return $this->hasMany(SupportCase::class);
    }

    public function assignedSupportCases()
    {
        return $this->hasMany(SupportCase::class, 'assigned_admin_id');
    }

    public function administratorAuditEvents()
    {
        return $this->hasMany(AdminAuditLog::class, 'admin_user_id');
    }

    public function oidcIdentities()
    {
        return $this->hasMany(OidcIdentity::class);
    }

    public function partnerMemberAccounts()
    {
        return $this->hasMany(PartnerMemberAccount::class);
    }

    public function contractorInvitationsAccepted()
    {
        return $this->hasMany(ContractorInvitation::class, 'accepted_user_id');
    }
}
