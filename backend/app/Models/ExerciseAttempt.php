<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExerciseAttempt extends Model
{
    use HasFactory;

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_GRADED = 'graded';

    protected $fillable = [
        'center_id',
        'exercise_id',
        'student_profile_id',
        'attempt_number',
        'status',
        'passing_score_snapshot',
        'show_correct_answers_after_submission_snapshot',
        'score',
        'max_score',
        'percentage',
        'passed',
        'started_at',
        'submitted_at',
        'graded_at',
    ];

    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'passing_score_snapshot' => 'decimal:2',
            'show_correct_answers_after_submission_snapshot' => 'boolean',
            'score' => 'decimal:2',
            'max_score' => 'decimal:2',
            'percentage' => 'decimal:2',
            'passed' => 'boolean',
            'started_at' => 'datetime',
            'submitted_at' => 'datetime',
            'graded_at' => 'datetime',
        ];
    }

    /**
     * Center that owns this attempt.
     */
    public function center(): BelongsTo
    {
        return $this->belongsTo(Center::class);
    }

    /**
     * Exercise associated with this attempt.
     */
    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    /**
     * Student profile that owns this attempt.
     */
    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    /**
     * Answer records created for this attempt.
     */
    public function answers(): HasMany
    {
        return $this->hasMany(
            ExerciseAttemptAnswer::class,
            'exercise_attempt_id'
        )->orderBy('id');
    }

    /**
     * Restrict queries to a specific center.
     */
    public function scopeForCenter(
        Builder $query,
        int $centerId
    ): Builder {
        return $query->where('center_id', $centerId);
    }

    /**
     * Restrict queries to a specific student profile.
     */
    public function scopeForStudent(
        Builder $query,
        int $studentProfileId
    ): Builder {
        return $query->where('student_profile_id', $studentProfileId);
    }

    /**
     * Restrict queries to a specific exercise.
     */
    public function scopeForExercise(
        Builder $query,
        int $exerciseId
    ): Builder {
        return $query->where('exercise_id', $exerciseId);
    }

    /**
     * Only in-progress attempts.
     */
    public function scopeInProgress(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_IN_PROGRESS);
    }

    /**
     * Only completed/graded attempts.
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->whereIn('status', [
            self::STATUS_SUBMITTED,
            self::STATUS_GRADED,
        ]);
    }

    public function isInProgress(): bool
    {
        return $this->status === self::STATUS_IN_PROGRESS;
    }

    public function isSubmitted(): bool
    {
        return $this->status === self::STATUS_SUBMITTED;
    }

    public function isGraded(): bool
    {
        return $this->status === self::STATUS_GRADED;
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [
            self::STATUS_SUBMITTED,
            self::STATUS_GRADED,
        ], true);
    }
}
