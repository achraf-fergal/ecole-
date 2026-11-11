<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends Model
{
    use HasFactory;

    public const PRICING_FIXED = 'fixed';

    public const PRICING_FROM = 'from';

    public const PRICING_UPON_REQUEST = 'upon_request';

    public const PRICING_FREE = 'free';

    protected $fillable = [
        'center_id',
        'language_id',
        'level_id',

        'source_locale',

        'title',
        'slug',

        'short_description',
        'description',
        'prerequisites',
        'target_audience',

        'study_format',
        'duration_hours',

        'cover_image_url',
        'cover_image_alt',

        'price',
        'currency',
        'pricing_mode',

        'is_curriculum_public',

        'meta_title',
        'meta_description',

        'status',
        'sort_order',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'duration_hours' => 'integer',
            'sort_order' => 'integer',
            'is_curriculum_public' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function center(): BelongsTo
    {
        return $this->belongsTo(Center::class);
    }
    /**
     * Educational modules belonging to this course.
     */
    public function modules(): HasMany
    {
        return $this->hasMany(CourseModule::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }
    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    public function translations(): HasMany
    {
        return $this->hasMany(CourseTranslation::class);
    }

    public function outcomes(): HasMany
    {
        return $this->hasMany(CourseOutcome::class);
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
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    public function isPublished(): bool
    {
        return $this->status === 'published'
            && $this->published_at !== null
            && $this->published_at->lte(now());
    }
}
