<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LessonRevision extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PUBLISHED,
        self::STATUS_SUPERSEDED,
    ];

    protected $fillable = [
        'center_id',
        'lesson_id',
        'version_number',
        'status',
        'title',
        'description',
        'content_locale',
        'duration_minutes',
        'created_by_user_id',
        'published_by_user_id',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'duration_minutes' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'created_by_user_id'
        );
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'published_by_user_id'
        );
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(LessonBlock::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function scopeForCenter(
        Builder $query,
        int $centerId
    ): Builder {
        return $query->where('center_id', $centerId);
    }

    public function scopeForLesson(
        Builder $query,
        int $lessonId
    ): Builder {
        return $query->where('lesson_id', $lessonId);
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where(
            'status',
            self::STATUS_DRAFT
        );
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_PUBLISHED)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED
            && $this->published_at !== null
            && $this->published_at->lte(now());
    }

    public function isSuperseded(): bool
    {
        return $this->status === self::STATUS_SUPERSEDED;
    }
}
