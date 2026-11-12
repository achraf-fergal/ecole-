<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Cohort extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_OPEN = 'open';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_OPEN,
        self::STATUS_IN_PROGRESS,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'center_id',
        'course_id',
        'teacher_user_id',
        'name',
        'code',
        'starts_at',
        'ends_at',
        'capacity',
        'location_label',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'capacity' => 'integer',
        ];
    }

    /**
     * Center owning this cohort.
     */
    public function center(): BelongsTo
    {
        return $this->belongsTo(Center::class);
    }

    /**
     * Course delivered by this cohort.
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Assigned teacher account.
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_user_id');
    }

    /**
     * Restrict cohorts to a specific tenant.
     */
    public function scopeForCenter(
        Builder $query,
        int $centerId
    ): Builder {
        return $query->where('center_id', $centerId);
    }

    /**
     * Return cohorts currently accepting enrollments.
     */
    public function scopeOpenForEnrollment(
        Builder $query
    ): Builder {
        return $query->where(
            'status',
            self::STATUS_OPEN
        );
    }

    /**
     * Return cohorts in chronological order.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query
            ->orderBy('starts_at')
            ->orderBy('id');
    }

    public function isOpenForEnrollment(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function hasTeacher(): bool
    {
        return $this->teacher_user_id !== null;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }
    /**
     * Recurring weekly schedules belonging to this cohort.
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(CohortSchedule::class)
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->orderBy('id');
    }
    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }
}
