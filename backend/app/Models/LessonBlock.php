<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonBlock extends Model
{
    use HasFactory;

    /*
    |--------------------------------------------------------------------------
    | Block Types
    |--------------------------------------------------------------------------
    */

    public const TYPE_TEXT = 'text';

    public const TYPE_MEDIA = 'media';

    public const TYPE_EXERCISE = 'exercise';

    public const TYPES = [
        self::TYPE_TEXT,
        self::TYPE_MEDIA,
        self::TYPE_EXERCISE,
    ];

    /*
    |--------------------------------------------------------------------------
    | Mass Assignment
    |--------------------------------------------------------------------------
    */

    protected $fillable = [
        'center_id',
        'lesson_id',
        'lesson_revision_id',
        'block_type',
        'sort_order',
        'content_json',
        'lesson_asset_id',
        'exercise_id',
        'settings',
    ];

    /*
    |--------------------------------------------------------------------------
    | Attribute Casting
    |--------------------------------------------------------------------------
    */

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'content_json' => 'array',
            'settings' => 'array',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Revision containing this block.
     */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(
            LessonRevision::class,
            'lesson_revision_id'
        );
    }

    /**
     * Lesson owning this block.
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /**
     * Media asset referenced by this block.
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(
            LessonAsset::class,
            'lesson_asset_id'
        );
    }

    /**
     * Exercise referenced by this block.
     */
    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Query Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Restrict blocks to a specific center.
     */
    public function scopeForCenter(
        Builder $query,
        int $centerId
    ): Builder {
        return $query->where('center_id', $centerId);
    }

    /**
     * Restrict blocks to a specific revision.
     */
    public function scopeForRevision(
        Builder $query,
        int $revisionId
    ): Builder {
        return $query->where(
            'lesson_revision_id',
            $revisionId
        );
    }

    /**
     * Return blocks in their intended display order.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /*
    |--------------------------------------------------------------------------
    | Type Helpers
    |--------------------------------------------------------------------------
    */

    public function isText(): bool
    {
        return $this->block_type === self::TYPE_TEXT;
    }

    public function isMedia(): bool
    {
        return $this->block_type === self::TYPE_MEDIA;
    }

    public function isExercise(): bool
    {
        return $this->block_type === self::TYPE_EXERCISE;
    }
}
