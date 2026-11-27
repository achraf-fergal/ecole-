<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Exercise extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ARCHIVED = 'archived';

    public const RELEASE_TIMING_IMMEDIATELY = 'immediately';

    public const RELEASE_TIMING_AFTER_FINAL_ATTEMPT = 'after_final_attempt';

    public const RELEASE_TIMINGS = [
        self::RELEASE_TIMING_IMMEDIATELY,
        self::RELEASE_TIMING_AFTER_FINAL_ATTEMPT,
    ];

    protected $fillable = [
        'center_id',
        'lesson_id',
        'title',
        'instructions',
        'max_attempts',
        'passing_score',
        'show_correct_answers_after_submission',
        'result_settings',
        'sort_order',
        'status',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'max_attempts' => 'integer',
            'passing_score' => 'decimal:2',
            'show_correct_answers_after_submission' => 'boolean',
            'result_settings' => 'array',
            'sort_order' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Exercise $exercise): void {
            if (
                $exercise->exists
                && $exercise->isDirty('show_correct_answers_after_submission')
                && ! $exercise->isDirty('result_settings')
            ) {
                $flag = (bool) $exercise->show_correct_answers_after_submission;
                $settings = is_array($exercise->result_settings)
                    ? $exercise->result_settings
                    : self::defaultResultSettings($flag);
                $settings['show_correct_answers'] = $flag;
                $settings['show_explanations'] = $flag;
                $exercise->result_settings = self::normalizeResultSettings($settings);
            }
        });
    }

    /**
     * Center that owns this exercise.
     */
    public function center(): BelongsTo
    {
        return $this->belongsTo(Center::class);
    }

    /**
     * Lesson containing this exercise.
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /**
     * Questions belonging to this exercise.
     */
    public function questions(): HasMany
    {
        return $this->hasMany(Question::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * Content blocks referencing this exercise.
     */
    public function blocks(): HasMany
    {
        return $this->hasMany(
            LessonBlock::class,
            'exercise_id'
        );
    }

    /**
     * Student attempts on this exercise.
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(
            ExerciseAttempt::class,
            'exercise_id'
        )->orderByDesc('attempt_number');
    }

    /**
     * Restrict exercises to one tenant.
     */
    public function scopeForCenter(
        Builder $query,
        int $centerId
    ): Builder {
        return $query->where('center_id', $centerId);
    }

    /**
     * Retrieve exercises that are published and available.
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_PUBLISHED)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    /**
     * Check whether the exercise is currently published.
     */
    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED
            && $this->published_at !== null
            && $this->published_at->lte(now());
    }

    /**
     * Check archival state.
     */
    public function isArchived(): bool
    {
        return $this->status === self::STATUS_ARCHIVED;
    }

    /**
     * Check whether attempts are unlimited.
     */
    public function hasUnlimitedAttempts(): bool
    {
        return $this->max_attempts === null;
    }

    /**
     * Default granular result visibility settings for new or legacy exercises.
     *
     * @return array<string, bool|string>
     */
    public static function defaultResultSettings(bool $showCorrectAnswers = false): array
    {
        return [
            'show_score' => true,
            'show_percentage' => true,
            'show_pass_fail' => true,
            'show_question_results' => true,
            'show_student_answers' => true,
            'show_correct_answers' => $showCorrectAnswers,
            'show_explanations' => $showCorrectAnswers,
            'show_points_per_question' => true,
            'release_timing' => self::RELEASE_TIMING_IMMEDIATELY,
        ];
    }

    /**
     * Normalize result visibility settings and enforce field dependencies.
     *
     * @param  array<string, mixed>|null  $settings
     * @return array<string, bool|string>
     */
    public static function normalizeResultSettings(?array $settings, ?bool $legacyShowCorrect = null): array
    {
        $base = self::defaultResultSettings($legacyShowCorrect ?? false);

        if (is_array($settings)) {
            foreach ([
                'show_score',
                'show_percentage',
                'show_pass_fail',
                'show_question_results',
                'show_student_answers',
                'show_correct_answers',
                'show_explanations',
                'show_points_per_question',
            ] as $boolKey) {
                if (array_key_exists($boolKey, $settings)) {
                    $base[$boolKey] = (bool) $settings[$boolKey];
                }
            }

            if (
                isset($settings['release_timing'])
                && is_string($settings['release_timing'])
                && in_array($settings['release_timing'], self::RELEASE_TIMINGS, true)
            ) {
                $base['release_timing'] = $settings['release_timing'];
            }
        }

        // Dependencies: correct answers and explanations cannot be exposed when question review is disabled.
        if (! $base['show_question_results']) {
            $base['show_correct_answers'] = false;
            $base['show_explanations'] = false;
        }

        return $base;
    }

    /**
     * Resolve effective result visibility settings for this exercise.
     *
     * @return array<string, bool|string>
     */
    public function resolvedResultSettings(): array
    {
        return self::normalizeResultSettings(
            is_array($this->result_settings) ? $this->result_settings : null,
            (bool) $this->show_correct_answers_after_submission
        );
    }
}
