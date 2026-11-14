<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\EnrollmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Enrollment extends Model
{
    /** @use HasFactory<EnrollmentFactory> */
    use HasFactory;

    /*
    |--------------------------------------------------------------------------
    | Enrollment Statuses
    |--------------------------------------------------------------------------
    */

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_SUSPENDED,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    /*
    |--------------------------------------------------------------------------
    | Mass Assignment
    |--------------------------------------------------------------------------
    */

    protected $fillable = [
        'center_id',
        'student_profile_id',
        'cohort_id',
        'granted_by_user_id',
        'status',
        'start_at',
        'end_at',
    ];

    /*
    |--------------------------------------------------------------------------
    | Attribute Casting
    |--------------------------------------------------------------------------
    */

    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Center owning this enrollment.
     */
    public function center(): BelongsTo
    {
        return $this->belongsTo(Center::class);
    }

    /**
     * Student receiving access.
     */
    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(
            StudentProfile::class
        );
    }

    /**
     * Teaching group associated with this enrollment.
     */
    public function cohort(): BelongsTo
    {
        return $this->belongsTo(Cohort::class);
    }

    /**
     * User who granted this enrollment.
     */
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'granted_by_user_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Query Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Restrict enrollments to one center.
     */
    public function scopeForCenter(
        Builder $query,
        int $centerId
    ): Builder {
        return $query->where(
            'center_id',
            $centerId
        );
    }

    /**
     * Filter enrollments belonging to a student profile.
     */
    public function scopeForStudentProfile(
        Builder $query,
        int $studentProfileId
    ): Builder {
        return $query->where(
            'student_profile_id',
            $studentProfileId
        );
    }

    /**
     * Return enrollments that grant access at a given time.
     */
    public function scopeActiveAt(
        Builder $query,
        ?CarbonInterface $at = null
    ): Builder {
        $at ??= now();

        return $query
            ->where('status', self::STATUS_ACTIVE)
            ->where('start_at', '<=', $at)
            ->where(function (Builder $dateQuery) use ($at) {
                $dateQuery
                    ->whereNull('end_at')
                    ->orWhere('end_at', '>=', $at);
            });
    }

    /*
    |--------------------------------------------------------------------------
    | State Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Check whether this enrollment grants access
     * at the provided date and time.
     */
    public function isActiveAt(
        ?CarbonInterface $at = null
    ): bool {
        $at ??= now();

        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }

        if (
            $this->start_at === null
            || $this->start_at->greaterThan($at)
        ) {
            return false;
        }

        if (
            $this->end_at !== null
            && $this->end_at->lessThan($at)
        ) {
            return false;
        }

        return true;
    }

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }
}
