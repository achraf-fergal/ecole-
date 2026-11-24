<?php

namespace App\Services\Learning;

use App\Models\Center;
use App\Models\Lesson;
use App\Models\LessonAsset;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CloudflareStreamService
{
    private const DEFAULT_TUS_CHUNK_BYTES = 52428800;

    private const DEFAULT_ACCOUNT_MAX_UPLOAD_BYTES = 29999999999;

    public function __construct(
        protected LessonRevisionService $lessonRevisionService
    ) {}

    /** Return the configured application limit bounded by the confirmed account limit. */
    public function maxUploadBytes(): int
    {
        $applicationLimit = max(
            1,
            (int) config('services.cloudflare_stream.max_upload_bytes', 5368709120)
        );
        $accountLimit = max(
            1,
            (int) config(
                'services.cloudflare_stream.account_max_upload_bytes',
                self::DEFAULT_ACCOUNT_MAX_UPLOAD_BYTES
            )
        );

        return min($applicationLimit, $accountLimit);
    }

    /**
     * Create a one-time Basic POST Direct Creator Upload endpoint.
     *
     * @return array{uid: string, upload_url: string, expires_at: string}
     */
    public function createDirectUpload(
        Center $center,
        Lesson $lesson,
        string $title
    ): array {
        [$accountId, $apiToken] = $this->credentials();
        $expiresAt = $this->uploadExpiresAt();
        $origins = $this->allowedOrigins();

        $endpoint = sprintf(
            'https://api.cloudflare.com/client/v4/accounts/%s/stream/direct_upload',
            rawurlencode($accountId)
        );

        try {
            $response = Http::withToken($apiToken)
                ->acceptJson()
                ->timeout(20)
                ->post($endpoint, [
                    'maxDurationSeconds' => $this->maxDurationSeconds(),
                    'expiry' => $expiresAt,
                    'requireSignedURLs' => true,
                    'allowedOrigins' => $origins,
                    'creator' => 'center-'.$center->id,
                    'meta' => [
                        'name' => $title,
                        'center_id' => (string) $center->id,
                        'lesson_id' => (string) $lesson->id,
                    ],
                ]);
        } catch (ConnectionException) {
            abort(503, 'Video upload service is temporarily unavailable.');
        }

        $payload = $response->json();
        $uid = data_get($payload, 'result.uid');
        $uploadUrl = data_get($payload, 'result.uploadURL');

        if (
            $this->isValidUid($uid)
            && (
                ! $response->successful()
                || data_get($payload, 'success') !== true
                || ! $this->isTrustedUploadUrl($uploadUrl, 'upload.videodelivery.net')
            )
        ) {
            $this->deleteVideo($uid);
        }

        abort_unless(
            $response->successful()
                && data_get($payload, 'success') === true
                && $this->isValidUid($uid)
                && $this->isTrustedUploadUrl($uploadUrl, 'upload.videodelivery.net'),
            503,
            'Unable to create a secure video upload.'
        );

        return [
            'uid' => $uid,
            'upload_url' => $uploadUrl,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Provision a one-time Cloudflare TUS Direct Creator Upload endpoint.
     *
     * @return array{uid: string, upload_url: string, expires_at: string, chunk_size: int}
     */
    public function createTusUploadEndpoint(
        Center $center,
        Lesson $lesson,
        string $title,
        string $filename,
        string $mimeType,
        int $fileSize
    ): array {
        [$accountId, $apiToken] = $this->credentials();
        $expiresAt = $this->uploadExpiresAt();
        $origins = $this->allowedOrigins();

        $metadata = [
            'maxDurationSeconds '.base64_encode((string) $this->maxDurationSeconds()),
            'expiry '.base64_encode($expiresAt),
            'requiresignedurls',
            'name '.base64_encode($title),
            'filename '.base64_encode($filename),
            'filetype '.base64_encode($mimeType),
            'allowedorigins '.base64_encode((string) json_encode($origins)),
        ];

        $endpoint = sprintf(
            'https://api.cloudflare.com/client/v4/accounts/%s/stream?direct_user=true',
            rawurlencode($accountId)
        );

        try {
            $response = Http::withToken($apiToken)
                ->acceptJson()
                ->withHeaders([
                    'Tus-Resumable' => '1.0.0',
                    'Upload-Length' => (string) $fileSize,
                    'Upload-Metadata' => implode(',', $metadata),
                    'Upload-Creator' => 'center-'.$center->id,
                ])
                ->timeout(20)
                ->post($endpoint);
        } catch (ConnectionException) {
            abort(503, 'Video upload service is temporarily unavailable.');
        }

        $uid = $response->header('stream-media-id');
        $uploadUrl = $response->header('Location');

        if (
            $this->isValidUid($uid)
            && (
                ! $response->successful()
                || ! $this->isTrustedUploadUrl($uploadUrl, 'api.cloudflare.com')
            )
        ) {
            $this->deleteVideo($uid);
        }

        abort_unless(
            $response->successful()
                && $this->isValidUid($uid)
                && $this->isTrustedUploadUrl($uploadUrl, 'api.cloudflare.com'),
            503,
            'Unable to create a secure resumable video upload.'
        );

        return [
            'uid' => $uid,
            'upload_url' => $uploadUrl,
            'expires_at' => $expiresAt,
            'chunk_size' => $this->tusChunkSize(),
        ];
    }

    /** Fetch canonical processing metadata from the Cloudflare API. */
    public function getVideoDetails(string $uid): array
    {
        abort_unless($this->isValidUid($uid), 404, 'Video asset not found.');
        [$accountId, $apiToken] = $this->credentials();

        $endpoint = sprintf(
            'https://api.cloudflare.com/client/v4/accounts/%s/stream/%s',
            rawurlencode($accountId),
            rawurlencode($uid)
        );

        try {
            $response = Http::withToken($apiToken)
                ->acceptJson()
                ->timeout(15)
                ->get($endpoint);
        } catch (ConnectionException) {
            abort(503, 'Video status service is temporarily unavailable.');
        }

        $payload = $response->json();
        $details = data_get($payload, 'result');

        abort_unless(
            $response->successful()
                && data_get($payload, 'success') === true
                && is_array($details),
            503,
            'Unable to retrieve video processing status.'
        );

        return $details;
    }

    /** Delete a remote video when local persistence or cancellation requires cleanup. */
    public function deleteVideo(string $uid): bool
    {
        if (! $this->isValidUid($uid)) {
            return false;
        }

        try {
            [$accountId, $apiToken] = $this->credentials();
            $response = Http::withToken($apiToken)
                ->acceptJson()
                ->timeout(15)
                ->delete(sprintf(
                    'https://api.cloudflare.com/client/v4/accounts/%s/stream/%s',
                    rawurlencode($accountId),
                    rawurlencode($uid)
                ));
        } catch (ConnectionException) {
            return false;
        } catch (HttpException) {
            return false;
        }

        return $response->status() === 404
            || ($response->successful()
                && data_get($response->json(), 'success') === true);
    }

    /**
     * Apply provider state only while an asset is still pending. Terminal and
     * archived states are immutable, and published revision references remain protected.
     *
     * @return array{asset: LessonAsset, status: string, progress: ?float, error_code: ?string}
     */
    public function synchronizeAssetStatus(
        LessonAsset $asset,
        array $details
    ): array {
        abort_unless(
            $asset->isProviderVideo()
                && $asset->video_provider === LessonAsset::VIDEO_PROVIDER_CLOUDFLARE_STREAM,
            404,
            'Video asset not found.'
        );

        return DB::transaction(function () use ($asset, $details): array {
            Lesson::query()
                ->forCenter((int) $asset->center_id)
                ->whereKey($asset->lesson_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedAsset = LessonAsset::query()
                ->forCenter((int) $asset->center_id)
                ->where('lesson_id', $asset->lesson_id)
                ->whereKey($asset->id)
                ->lockForUpdate()
                ->firstOrFail();

            $state = strtolower((string) data_get($details, 'status.state'));
            $progress = $this->processingProgress($details);
            $errorCode = $this->safeErrorCode(
                data_get($details, 'status.errorReasonCode')
                    ?? data_get($details, 'status.errReasonCode')
            );

            if ($lockedAsset->status === LessonAsset::STATUS_PROCESSING) {
                $providerSize = data_get($details, 'size');
                $hasValidProviderSize = is_numeric($providerSize)
                    && (int) $providerSize > 0;
                $providerSizeWithinLimit = $hasValidProviderSize
                    && (int) $providerSize <= $this->maxUploadBytes();
                $fullyReady = $this->isFullyReady($details);

                if ($fullyReady && ! $providerSizeWithinLimit) {
                    $nextStatus = LessonAsset::STATUS_FAILED;
                } elseif ($fullyReady) {
                    $nextStatus = LessonAsset::STATUS_ACTIVE;
                } elseif ($state === 'error') {
                    $nextStatus = LessonAsset::STATUS_FAILED;
                } else {
                    $nextStatus = LessonAsset::STATUS_PROCESSING;
                }

                if ($fullyReady && ! $providerSizeWithinLimit) {
                    $errorCode = $hasValidProviderSize
                        ? 'UPLOAD_TOO_LARGE'
                        : 'UPLOAD_SIZE_UNVERIFIED';

                    if (! $this->deleteVideo($lockedAsset->provider_asset_id)) {
                        Log::warning('Cloudflare video cleanup failed after the provider-reported size was rejected.', [
                            'asset_id' => $lockedAsset->id,
                            'center_id' => $lockedAsset->center_id,
                            'lesson_id' => $lockedAsset->lesson_id,
                        ]);
                    }
                }

                if ($nextStatus !== LessonAsset::STATUS_PROCESSING) {
                    $this->lessonRevisionService
                        ->assertAssetCanBeModified($lockedAsset);

                    $attributes = ['status' => $nextStatus];

                    if ($nextStatus === LessonAsset::STATUS_ACTIVE) {
                        $attributes += $this->verifiedAssetMetadata($details);
                    }

                    $lockedAsset->forceFill($attributes)->save();
                }
            }

            return [
                'asset' => $lockedAsset->refresh(),
                'status' => $lockedAsset->status,
                'progress' => $progress,
                'error_code' => $state === 'error'
                    || in_array(
                        $errorCode,
                        ['UPLOAD_TOO_LARGE', 'UPLOAD_SIZE_UNVERIFIED'],
                        true
                    )
                    ? $errorCode
                    : null,
            ];
        }, 3);
    }

    /** Restore an archived video only after Cloudflare confirms full readiness. */
    public function restoreReadyVideo(LessonAsset $asset): LessonAsset
    {
        abort_unless(
            $asset->isProviderVideo()
                && $asset->video_provider === LessonAsset::VIDEO_PROVIDER_CLOUDFLARE_STREAM
                && $asset->status === LessonAsset::STATUS_ARCHIVED,
            409,
            'The video provider reference is invalid.'
        );

        $details = $this->getVideoDetails($asset->provider_asset_id);

        abort_unless(
            $this->isFullyReady($details),
            409,
            'The video is not ready to be restored.'
        );

        $verifiedMetadata = $this->verifiedAssetMetadata($details);

        return DB::transaction(function () use (
            $asset,
            $verifiedMetadata
        ): LessonAsset {
            Lesson::query()
                ->forCenter((int) $asset->center_id)
                ->whereKey($asset->lesson_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedAsset = LessonAsset::query()
                ->forCenter((int) $asset->center_id)
                ->where('lesson_id', $asset->lesson_id)
                ->whereKey($asset->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless(
                $lockedAsset->status === LessonAsset::STATUS_ARCHIVED,
                409,
                'Only archived videos can be restored.'
            );

            $this->lessonRevisionService->assertAssetCanBeModified($lockedAsset);

            $lockedAsset->forceFill([
                'status' => LessonAsset::STATUS_ACTIVE,
                ...$verifiedMetadata,
            ])->save();

            return $lockedAsset->refresh();
        }, 3);
    }

    /** Cancel a pending upload without racing publication or a status webhook. */
    public function cancelPendingUpload(LessonAsset $asset): LessonAsset
    {
        abort_unless(
            $asset->isProviderVideo()
                && $asset->video_provider === LessonAsset::VIDEO_PROVIDER_CLOUDFLARE_STREAM,
            404,
            'Video asset not found.'
        );

        return DB::transaction(function () use ($asset): LessonAsset {
            Lesson::query()
                ->forCenter((int) $asset->center_id)
                ->whereKey($asset->lesson_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedAsset = LessonAsset::query()
                ->forCenter((int) $asset->center_id)
                ->where('lesson_id', $asset->lesson_id)
                ->whereKey($asset->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless(
                $lockedAsset->status === LessonAsset::STATUS_PROCESSING,
                409,
                'Only pending video uploads can be cancelled.'
            );

            $this->lessonRevisionService->assertAssetCanBeModified($lockedAsset);

            abort_unless(
                $this->deleteVideo($lockedAsset->provider_asset_id),
                503,
                'Unable to cancel the remote video upload.'
            );

            $lockedAsset->update([
                'status' => LessonAsset::STATUS_ARCHIVED,
            ]);

            return $lockedAsset->refresh();
        }, 3);
    }

    /**
     * Generate short-lived playback URLs for a protected Stream video.
     *
     * @return array{
     *     provider: string,
     *     player_url: string,
     *     hls_url: string,
     *     dash_url: string,
     *     expires_at: string
     * }
     */
    public function createPlaybackAccess(LessonAsset $asset): array
    {
        abort_unless(
            $asset->isProviderVideo()
                && $asset->video_provider === LessonAsset::VIDEO_PROVIDER_CLOUDFLARE_STREAM
                && $asset->status === LessonAsset::STATUS_ACTIVE,
            404,
            'Video asset not found.'
        );

        $accountId = config('services.cloudflare_stream.account_id');
        $apiToken = config('services.cloudflare_stream.api_token');
        $customerCode = config('services.cloudflare_stream.customer_code');

        abort_unless(
            filled($accountId)
                && filled($apiToken)
                && filled($customerCode),
            503,
            'Video playback is not configured.'
        );

        $ttl = max(
            1,
            min(
                60,
                (int) config(
                    'services.cloudflare_stream.token_ttl_minutes',
                    10
                )
            )
        );

        $expiresAt = now()->addMinutes($ttl);

        $endpoint = sprintf(
            'https://api.cloudflare.com/client/v4/accounts/%s/stream/%s/token',
            rawurlencode($accountId),
            rawurlencode($asset->provider_asset_id)
        );

        try {
            $response = Http::withToken($apiToken)
                ->acceptJson()
                ->timeout(10)
                ->post($endpoint, [
                    'exp' => $expiresAt->timestamp,
                ]);
        } catch (ConnectionException $exception) {
            report($exception);

            abort(
                503,
                'Video playback service is temporarily unavailable.'
            );
        }

        $payload = $response->json();

        $signedToken = data_get($payload, 'result.token');

        if (
            ! $response->successful()
            || data_get($payload, 'success') !== true
            || ! filled($signedToken)
        ) {
            abort(
                503,
                'Unable to generate secure video playback access.'
            );
        }

        $baseUrl = sprintf(
            'https://customer-%s.cloudflarestream.com/%s',
            $customerCode,
            $signedToken
        );

        return [
            'type' => LessonAsset::VIDEO_PROVIDER_CLOUDFLARE_STREAM,
            'provider' => LessonAsset::VIDEO_PROVIDER_CLOUDFLARE_STREAM,
            'player_url' => $baseUrl.'/iframe',
            'hls_url' => $baseUrl.'/manifest/video.m3u8',
            'dash_url' => $baseUrl.'/manifest/video.mpd',
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    /** @return array{0: string, 1: string} */
    private function credentials(): array
    {
        $accountId = config('services.cloudflare_stream.account_id');
        $apiToken = config('services.cloudflare_stream.api_token');

        abort_unless(
            filled($accountId) && filled($apiToken),
            503,
            'Video upload and status services are not configured.'
        );

        return [(string) $accountId, (string) $apiToken];
    }

    private function uploadExpiresAt(): string
    {
        $ttl = max(
            5,
            min(60, (int) config('services.cloudflare_stream.upload_expiry_minutes', 30))
        );

        return now()->addMinutes($ttl)->utc()->toIso8601String();
    }

    private function maxDurationSeconds(): int
    {
        return max(
            1,
            min(36000, (int) config('services.cloudflare_stream.max_duration_seconds', 3600))
        );
    }

    /** @return list<string> */
    private function allowedOrigins(): array
    {
        $configured = config('services.cloudflare_stream.allowed_origins', []);
        $origins = [];

        foreach (is_array($configured) ? $configured : [] as $origin) {
            $origin = trim((string) $origin);
            $parts = parse_url($origin);

            if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
                continue;
            }

            $scheme = strtolower($parts['scheme']);
            $host = strtolower($parts['host']);
            $isLocalHttp = $scheme === 'http'
                && in_array($host, ['localhost', '127.0.0.1'], true);
            $hasOnlyOriginParts = ! isset($parts['user'])
                && ! isset($parts['pass'])
                && ! isset($parts['query'])
                && ! isset($parts['fragment'])
                && in_array($parts['path'] ?? '', ['', '/'], true);

            if (
                (! in_array($scheme, ['https', 'http'], true)
                    || ($scheme !== 'https' && ! $isLocalHttp)
                    || str_contains($host, '*')
                    || ! $hasOnlyOriginParts)
            ) {
                continue;
            }

            $normalized = $scheme.'://'.$host;
            if (isset($parts['port'])) {
                $normalized .= ':'.$parts['port'];
            }
            $origins[] = $normalized;
        }

        $origins = array_values(array_unique($origins));

        abort_unless(
            $origins !== [],
            503,
            'Video upload origins are not configured safely.'
        );

        return $origins;
    }

    private function tusChunkSize(): int
    {
        $configured = (int) config(
            'services.cloudflare_stream.tus_chunk_size_bytes',
            self::DEFAULT_TUS_CHUNK_BYTES
        );
        $minimum = 5 * 1024 * 1024;
        $maximum = 200 * 1024 * 1024;
        $unit = 256 * 1024;
        $bounded = max($minimum, min($maximum, $configured));

        return (int) (floor($bounded / $unit) * $unit);
    }

    private function isValidUid(mixed $uid): bool
    {
        return is_string($uid)
            && preg_match('/^[A-Za-z0-9_-]{16,128}$/D', $uid) === 1;
    }

    private function isTrustedUploadUrl(mixed $url, string $expectedHost): bool
    {
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && strtolower((string) ($parts['host'] ?? '')) === $expectedHost
            && ! isset($parts['user'], $parts['pass']);
    }

    private function isFullyReady(array $details): bool
    {
        return data_get($details, 'readyToStream') === true
            && strtolower((string) data_get($details, 'status.state')) === 'ready'
            && is_numeric(data_get($details, 'status.pctComplete'))
            && (float) data_get($details, 'status.pctComplete') >= 100;
    }

    private function processingProgress(array $details): ?float
    {
        $progress = data_get($details, 'status.pctComplete');

        return is_numeric($progress)
            ? max(0, min(100, (float) $progress))
            : null;
    }

    private function safeErrorCode(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $code = strtoupper(trim($value));

        return preg_match('/^[A-Z0-9_]{1,64}$/D', $code) === 1
            ? $code
            : 'PROVIDER_ERROR';
    }

    /** @return array<string, int> */
    private function verifiedAssetMetadata(array $details): array
    {
        $size = data_get($details, 'size');
        $duration = data_get($details, 'duration');

        abort_unless(
            is_numeric($size)
                && (int) $size > 0
                && (int) $size <= $this->maxUploadBytes(),
            409,
            'Cloudflare reported a video size outside the configured upload limit.'
        );

        $attributes = ['size_bytes' => (int) $size];

        if (is_numeric($duration) && (float) $duration >= 0) {
            $attributes['duration_seconds'] = (int) round((float) $duration);
        }

        return $attributes;
    }
}
