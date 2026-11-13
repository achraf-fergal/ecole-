<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

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
