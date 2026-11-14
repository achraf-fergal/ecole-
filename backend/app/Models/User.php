<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

#[Fillable(['name', 'email', 'password', 'google_id', 'status', 'token_version'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements JWTSubject, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    public const STATUS_SUSPENDED = 'suspended';

    /**
     * Get the identifier that will be stored in the subject claim of the JWT.
     */
    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    /**
     * Return a key value array, containing any custom claims to be added to the JWT.
     */
    public function getJWTCustomClaims(): array
    {
        return [
            'ver' => (int) ($this->token_version ?? 1),
        ];
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
            'token_version' => 'integer',
        ];
    }

    /**
     * Check whether the user account is active.
     */
    public function isActive(): bool
    {
        return ($this->status ?? self::STATUS_ACTIVE) === self::STATUS_ACTIVE;
    }

    /**
     * Check whether the user is a platform super administrator.
     */
    public function isPlatformAdmin(): bool
    {
        return $this->platformAdmin()->exists();
    }

    /**
     * Check if user has a specific role key in a specific center.
     */
    public function hasRoleInCenter(Center|int $center, string $roleKey): bool
    {
        $centerId = $center instanceof Center ? $center->id : $center;

        return $this->centerMemberships()
            ->where('center_id', $centerId)
            ->where('status', 'active')
            ->whereHas('roles', function ($query) use ($roleKey) {
                $query->where('key', $roleKey);
            })
            ->exists();
    }

    /**
     * Check if user has any of the specified roles in a center.
     *
     * @param  array<int, string>  $roleKeys
     */
    public function hasAnyRoleInCenter(Center|int $center, array $roleKeys): bool
    {
        $centerId = $center instanceof Center ? $center->id : $center;

        return $this->centerMemberships()
            ->where('center_id', $centerId)
            ->where('status', 'active')
            ->whereHas('roles', function ($query) use ($roleKeys) {
                $query->whereIn('key', $roleKeys);
            })
            ->exists();
    }

    public function centerMemberships(): HasMany
    {
        return $this->hasMany(CenterUser::class);
    }

    /**
     * Student profiles across all centers.
     *
     * One user may have a profile in multiple centers.
     */
    public function studentProfiles(): HasMany
    {
        return $this->hasMany(StudentProfile::class);
    }

    public function centers(): BelongsToMany
    {
        return $this->belongsToMany(
            Center::class,
            'center_user',
            'user_id',
            'center_id'
        )
            ->withPivot(['id', 'status'])
            ->withTimestamps();
    }

    /**
     * Cohorts assigned to this teacher account.
     *
     * The query must always be scoped to the current center
     * when used by teacher-facing features.
     */
    public function teachingCohorts(): HasMany
    {
        return $this->hasMany(
            Cohort::class,
            'teacher_user_id'
        );
    }

    public function platformAdmin(): HasOne
    {
        return $this->hasOne(PlatformAdmin::class);
    }
}
