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

    protected $fillable = [
        'center_id',
        'lesson_id',
        'title',
        'instructions',
        'max_attempts',
        'passing_score',
        'show_correct_answers_after_submission',
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
            'sort_order' => 'integer',
            'published_at' => 'datetime',
        ];
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
}
