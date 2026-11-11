<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonAsset extends Model
{
    use HasFactory;

    public const TYPE_FILE = 'file';

    public const TYPE_IMAGE = 'image';

    public const TYPE_VIDEO = 'video';

    public const TYPE_EXTERNAL_LINK = 'external_link';

    public const ASSET_TYPES = [
        self::TYPE_FILE,
        self::TYPE_IMAGE,
        self::TYPE_VIDEO,
        self::TYPE_EXTERNAL_LINK,
    ];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'center_id',
        'lesson_id',

        'title',
        'asset_type',

        'storage_disk',
        'storage_key',

        'original_filename',
        'mime_type',
        'size_bytes',

        'video_provider',
        'provider_asset_id',

        'external_url',

        'duration_seconds',
        'alt_text',
        'caption',

        'is_downloadable',
        'sort_order',

        'status',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'duration_seconds' => 'integer',
            'is_downloadable' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Center that owns this asset.
     */
    public function center(): BelongsTo
    {
        return $this->belongsTo(Center::class);
    }

    /**
     * Lesson containing this asset.
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /**
     * Filter assets by tenant.
     */
    public function scopeForCenter(
        Builder $query,
        int $centerId
    ): Builder {
        return $query->where('center_id', $centerId);
    }

    /**
     * Return active assets only.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where(
            'status',
            self::STATUS_ACTIVE
        );
    }

    /**
     * Check whether this asset uses private object storage.
     */
    public function isPrivateStorageAsset(): bool
    {
        return in_array(
            $this->asset_type,
            [self::TYPE_FILE, self::TYPE_IMAGE],
            true
        )
            && filled($this->storage_disk)
            && filled($this->storage_key);
    }

    /**
     * Check whether this asset represents a video provider resource.
     */
    public function isProviderVideo(): bool
    {
        return $this->asset_type === self::TYPE_VIDEO
            && filled($this->video_provider)
            && filled($this->provider_asset_id);
    }

    /**
     * Check whether the asset has been archived.
     */
    public function isArchived(): bool
    {
        return $this->status === self::STATUS_ARCHIVED;
    }
}
