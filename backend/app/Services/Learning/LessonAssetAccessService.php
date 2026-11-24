<?php

namespace App\Services\Learning;

use App\Models\LessonAsset;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class LessonAssetAccessService
{
    public function __construct(
        protected CloudflareStreamService $cloudflareStream,
        protected ExternalVideoService $externalVideo
    ) {}

    /**
     * Generate a temporary preview URL for a lesson asset.
     *
     * @return array<string, mixed>
     */
    public function createPreviewAccess(LessonAsset $asset): array
    {
        abort_unless(
            $asset->status === LessonAsset::STATUS_ACTIVE,
            404,
            'Asset not found.'
        );

        return match ($asset->asset_type) {
            LessonAsset::TYPE_FILE,
            LessonAsset::TYPE_IMAGE => $this->createStorageAccess($asset),

            LessonAsset::TYPE_VIDEO => match ($asset->video_provider) {
                LessonAsset::VIDEO_PROVIDER_CLOUDFLARE_STREAM => $this->cloudflareStream
                    ->createPlaybackAccess($asset),
                LessonAsset::VIDEO_PROVIDER_YOUTUBE,
                LessonAsset::VIDEO_PROVIDER_GOOGLE_DRIVE => $this->externalVideo
                    ->createPlaybackAccess($asset),
                default => abort(404, 'Video asset not found.'),
            },

            LessonAsset::TYPE_EXTERNAL_LINK => $this->externalLinkAccess($asset),

            default => abort(404, 'Unsupported asset type.'),
        };
    }

    /**
     * Generate temporary access for a private file or image.
     *
     * @return array<string, mixed>
     */
    private function createStorageAccess(LessonAsset $asset): array
    {
        [$diskName, $storageKey] = $this->resolveStorageLocation($asset);

        $disk = Storage::disk($diskName);

        abort_unless(
            $disk->exists($storageKey),
            404,
            'Asset file not found.'
        );

        $ttl = max(
            1,
            min(
                15,
                (int) config(
                    'learning.media.temporary_url_ttl_minutes',
                    3
                )
            )
        );

        $expiresAt = now()->addMinutes($ttl);

        $options = [];

        if ($diskName === 's3') {
            $options = [
                'ResponseContentType' => $asset->mime_type
                    ?: 'application/octet-stream',

                'ResponseContentDisposition' => 'inline; filename="'
                    .$this->safeFilename($asset)
                    .'"',
            ];
        }

        $url = $disk->temporaryUrl(
            $storageKey,
            $expiresAt,
            $options
        );

        return [
            'type' => 'stored_asset',
            'asset_id' => $asset->id,
            'asset_type' => $asset->asset_type,
            'url' => $url,
            'expires_at' => $expiresAt->toIso8601String(),
            'mime_type' => $asset->mime_type,
            'can_download' => $asset->is_downloadable,
            'alt_text' => $asset->alt_text,
            'caption' => $asset->caption,
        ];
    }

    /**
     * Download an asset only when its download permission is enabled.
     */
    public function download(LessonAsset $asset): Response
    {
        abort_unless(
            $asset->is_downloadable,
            403,
            'Downloading this asset is not permitted.'
        );

        abort_unless(
            in_array(
                $asset->asset_type,
                [LessonAsset::TYPE_FILE, LessonAsset::TYPE_IMAGE],
                true
            ),
            422,
            'This asset cannot be downloaded using this endpoint.'
        );

        [$diskName, $storageKey] = $this->resolveStorageLocation($asset);

        $disk = Storage::disk($diskName);

        abort_unless(
            $disk->exists($storageKey),
            404,
            'Asset file not found.'
        );

        $filename = $this->safeFilename($asset);

        if ($diskName === 's3') {
            $expiresAt = now()->addMinutes(3);

            $url = $disk->temporaryUrl(
                $storageKey,
                $expiresAt,
                [
                    'ResponseContentType' => $asset->mime_type
                        ?: 'application/octet-stream',

                    'ResponseContentDisposition' => 'attachment; filename="'
                        .$filename
                        .'"',
                ]
            );

            return redirect()->away($url);
        }

        return $disk->download(
            $storageKey,
            $filename,
            [
                'Content-Type' => $asset->mime_type
                    ?: 'application/octet-stream',

                'X-Content-Type-Options' => 'nosniff',

                'Cache-Control' => 'private, no-store',
            ]
        );
    }

    /**
     * Validate an external HTTPS link before returning it to the client.
     *
     * @return array<string, mixed>
     */
    private function externalLinkAccess(LessonAsset $asset): array
    {
        $url = $asset->external_url;

        $parts = is_string($url) ? parse_url($url) : false;

        abort_unless(
            is_array($parts)
                && filter_var($url, FILTER_VALIDATE_URL)
                && strtolower($parts['scheme'] ?? '') === 'https'
                && filled($parts['host'] ?? null)
                && ! isset($parts['user'])
                && ! isset($parts['pass']),
            404,
            'External resource is unavailable.'
        );

        return [
            'type' => 'external_link',
            'asset_id' => $asset->id,
            'url' => $url,
            'expires_at' => null,
            'can_download' => false,
            'title' => $asset->title,
        ];
    }

    /**
     * Resolve an allowed private storage disk and safe relative path.
     *
     * @return array{0: string, 1: string}
     */
    private function resolveStorageLocation(LessonAsset $asset): array
    {
        abort_unless(
            $asset->isPrivateStorageAsset(),
            404,
            'Private asset reference is invalid.'
        );

        $diskName = (string) $asset->storage_disk;

        $allowedDisks = config(
            'learning.media.allowed_disks',
            ['private', 's3']
        );

        abort_unless(
            in_array($diskName, $allowedDisks, true),
            404,
            'Storage disk is not allowed.'
        );

        $storageKey = (string) $asset->storage_key;
        $normalizedStorageKey = str_replace('\\', '/', $storageKey);
        $segments = explode('/', $normalizedStorageKey);

        abort_unless(
            $normalizedStorageKey !== ''
                && ! str_starts_with($normalizedStorageKey, '/')
                && ! str_contains($normalizedStorageKey, "\0")
                && ! in_array('..', $segments, true)
                && ! in_array('.', $segments, true),
            404,
            'Invalid storage reference.'
        );

        return [$diskName, $normalizedStorageKey];
    }

    /**
     * Generate a safe download filename.
     */
    private function safeFilename(LessonAsset $asset): string
    {
        $filename = $asset->original_filename
            ?: $asset->title
            ?: 'learning-resource';

        $filename = basename(str_replace('\\', '/', $filename));

        $filename = preg_replace(
            '/[\x00-\x1F\x7F"\\\\\/]/u',
            '_',
            $filename
        );

        return mb_substr(
            $filename ?: 'learning-resource',
            0,
            180
        );
    }
}
