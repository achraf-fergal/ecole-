<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Lesson extends Model
{
    use HasFactory;

    public const TYPE_TEXT = 'text';

    public const TYPE_VIDEO = 'video';

    public const TYPE_IMAGE = 'image';

    public const TYPE_FILE = 'file';

    public const TYPE_QUIZ = 'quiz';

    public const TYPE_MIXED = 'mixed';

    public const CONTENT_TYPES = [
        self::TYPE_TEXT,
        self::TYPE_VIDEO,
        self::TYPE_IMAGE,
        self::TYPE_FILE,
        self::TYPE_QUIZ,
        self::TYPE_MIXED,
    ];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'center_id',
        'course_module_id',

        'title',
        'description',

        'content_type',
        'body',
        'content_locale',

        'duration_minutes',
        'sort_order',

        'status',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'sort_order' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    public function center(): BelongsTo
    {
        return $this->belongsTo(Center::class);
    }

    public function courseModule(): BelongsTo
    {
        return $this->belongsTo(CourseModule::class);
    }

    public function scopeForCenter(
        Builder $query,
        int $centerId
    ): Builder {
        return $query->where('center_id', $centerId);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_PUBLISHED)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED
            && $this->published_at !== null
            && $this->published_at->lte(now());
    }/**
    * Files, images, videos and external resources
    * belonging to this lesson.
    */
   public function assets(): HasMany
   {
       return $this->hasMany(LessonAsset::class)
           ->orderBy('sort_order')
           ->orderBy('id');
   }
   /**
    * Exercises belonging to this lesson.
    */
   public function exercises(): HasMany
   {
       return $this->hasMany(Exercise::class)
           ->orderBy('sort_order')
           ->orderBy('id');
   }
    public function isArchived(): bool
    {
        return $this->status === self::STATUS_ARCHIVED;
    }
}