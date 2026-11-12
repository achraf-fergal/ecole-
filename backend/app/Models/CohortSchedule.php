<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CohortSchedule extends Model
{
    use HasFactory;

    public const MONDAY = 1;

    public const TUESDAY = 2;

    public const WEDNESDAY = 3;

    public const THURSDAY = 4;

    public const FRIDAY = 5;

    public const SATURDAY = 6;

    public const SUNDAY = 7;

    public const DAYS_OF_WEEK = [
        self::MONDAY => 'Monday',
        self::TUESDAY => 'Tuesday',
        self::WEDNESDAY => 'Wednesday',
        self::THURSDAY => 'Thursday',
        self::FRIDAY => 'Friday',
        self::SATURDAY => 'Saturday',
        self::SUNDAY => 'Sunday',
    ];

    protected $fillable = [
        'center_id',
        'cohort_id',
        'day_of_week',
        'start_time',
        'end_time',
        'location_label',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
        ];
    }

    /**
     * Center owning this schedule.
     */
    public function center(): BelongsTo
    {
        return $this->belongsTo(Center::class);
    }

    /**
     * Cohort containing this recurring schedule.
     */
    public function cohort(): BelongsTo
    {
        return $this->belongsTo(Cohort::class);
    }

    /**
     * Filter schedule records by tenant.
     */
    public function scopeForCenter(
        Builder $query,
        int $centerId
    ): Builder {
        return $query->where('center_id', $centerId);
    }

    /**
     * Filter schedules by ISO weekday.
     */
    public function scopeForDay(
        Builder $query,
        int $dayOfWeek
    ): Builder {
        return $query->where('day_of_week', $dayOfWeek);
    }

    /**
     * Return schedules in weekly chronological order.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->orderBy('id');
    }

    /**
     * Get the English name of the scheduled day.
     */
    public function getDayNameAttribute(): ?string
    {
        return self::DAYS_OF_WEEK[$this->day_of_week] ?? null;
    }
}
