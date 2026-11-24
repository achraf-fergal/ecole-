<?php

namespace App\Services\Learning;

use App\Models\Center;
use App\Models\Lesson;
use App\Models\LessonAsset;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ExternalVideoService
{
    public function __construct(
        protected ExternalVideoUrlParser $urlParser
    ) {}

    /**
     * Create or reuse an external video asset inside the authorized lesson.
     *
     * @return array{asset: LessonAsset, created: bool}
     */
    public function createAsset(
        Center $center,
        Lesson $lesson,
        string $provider,
        string $url,
        ?string $title,
        ?string $caption
    ): array {
        $assetId = $this->urlParser->parse($provider, $url);

        if ($assetId === null) {
            throw ValidationException::withMessages([
                'url' => [$this->urlValidationMessage($provider)],
            ]);
        }

        return DB::transaction(function () use (
            $center,
            $lesson,
            $provider,
            $url,
            $assetId,
            $title,
            $caption
        ): array {
            $lockedLesson = Lesson::query()
                ->forCenter($center->id)
                ->whereKey($lesson->id)
                ->lockForUpdate()
                ->firstOrFail();

            $existingAsset = LessonAsset::query()
                ->forCenter($center->id)
                ->where('lesson_id', $lockedLesson->id)
                ->where('asset_type', LessonAsset::TYPE_VIDEO)
                ->where('video_provider', $provider)
                ->where('provider_asset_id', $assetId)
                ->first();

            if ($existingAsset !== null) {
                if ($existingAsset->status !== LessonAsset::STATUS_ACTIVE) {
                    throw new HttpException(
                        409,
                        'This video already exists in the lesson and must be active before it can be reused.'
                    );
                }

                return [
                    'asset' => $existingAsset,
                    'created' => false,
                ];
            }

            $providerName = $provider === LessonAsset::VIDEO_PROVIDER_YOUTUBE
                ? 'YouTube video'
                : 'Google Drive video';

            $asset = LessonAsset::query()->create([
                'center_id' => $center->id,
                'lesson_id' => $lockedLesson->id,
                'title' => filled($title) ? trim($title) : $providerName,
                'asset_type' => LessonAsset::TYPE_VIDEO,
                'storage_disk' => null,
                'storage_key' => null,
                'original_filename' => null,
                'mime_type' => null,
                'size_bytes' => null,
                'video_provider' => $provider,
                'provider_asset_id' => $assetId,
                'external_url' => $url,
                'duration_seconds' => null,
                'alt_text' => null,
                'caption' => $caption,
                'is_downloadable' => false,
                'sort_order' => ((int) $lockedLesson->assets()->max('sort_order')) + 1,
                'status' => LessonAsset::STATUS_ACTIVE,
            ]);

            return [
                'asset' => $asset,
                'created' => true,
            ];
        }, 3);
    }

    /** Build a provider URL using only a previously validated provider ID. */
    public function createPlaybackAccess(LessonAsset $asset): array
    {
        $provider = (string) $asset->video_provider;
        $assetId = (string) $asset->provider_asset_id;
        $parsedAssetId = is_string($asset->external_url)
            ? $this->urlParser->parse($provider, $asset->external_url)
            : null;

        abort_unless(
            $asset->asset_type === LessonAsset::TYPE_VIDEO
                && $asset->status === LessonAsset::STATUS_ACTIVE
                && $parsedAssetId !== null
                && hash_equals($parsedAssetId, $assetId),
            404,
            'Video asset not found.'
        );

        return match ($provider) {
            LessonAsset::VIDEO_PROVIDER_YOUTUBE => [
                'type' => 'external_video',
                'provider' => LessonAsset::VIDEO_PROVIDER_YOUTUBE,
                'embed_url' => sprintf(
                    'https://www.youtube-nocookie.com/embed/%s?cc_load_policy=1&playsinline=1',
                    rawurlencode($assetId)
                ),
                'open_url' => sprintf(
                    'https://www.youtube.com/watch?v=%s',
                    rawurlencode($assetId)
                ),
                'expires_at' => null,
            ],
            LessonAsset::VIDEO_PROVIDER_GOOGLE_DRIVE => [
                'type' => 'external_video',
                'provider' => LessonAsset::VIDEO_PROVIDER_GOOGLE_DRIVE,
                'embed_url' => sprintf(
                    'https://drive.google.com/file/d/%s/preview',
                    rawurlencode($assetId)
                ),
                'open_url' => sprintf(
                    'https://drive.google.com/file/d/%s/view',
                    rawurlencode($assetId)
                ),
                'expires_at' => null,
            ],
            default => abort(404, 'Video asset not found.'),
        };
    }

    /** Ensure a stored external video still matches its original validated URL. */
    public function assertValidAssetReference(LessonAsset $asset): void
    {
        $provider = (string) $asset->video_provider;
        $assetId = (string) $asset->provider_asset_id;
        $parsedAssetId = is_string($asset->external_url)
            ? $this->urlParser->parse($provider, $asset->external_url)
            : null;

        abort_unless(
            $asset->asset_type === LessonAsset::TYPE_VIDEO
                && in_array($provider, [
                    LessonAsset::VIDEO_PROVIDER_YOUTUBE,
                    LessonAsset::VIDEO_PROVIDER_GOOGLE_DRIVE,
                ], true)
                && $parsedAssetId !== null
                && hash_equals($parsedAssetId, $assetId),
            409,
            'The external video reference is invalid.'
        );
    }

    private function urlValidationMessage(string $provider): string
    {
        return match ($provider) {
            LessonAsset::VIDEO_PROVIDER_YOUTUBE => 'Enter a valid HTTPS YouTube video link.',
            LessonAsset::VIDEO_PROVIDER_GOOGLE_DRIVE => 'Enter a valid HTTPS Google Drive file link.',
            default => 'Choose a supported external video provider.',
        };
    }
}
