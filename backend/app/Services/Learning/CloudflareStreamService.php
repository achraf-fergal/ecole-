<?php

namespace App\Services\Learning;

use App\Models\LessonAsset;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class CloudflareStreamService
{
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
                && $asset->video_provider === 'cloudflare_stream',
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
            'type' => 'cloudflare_stream',
            'provider' => 'cloudflare_stream',
            'player_url' => $baseUrl.'/iframe',
            'hls_url' => $baseUrl.'/manifest/video.m3u8',
            'dash_url' => $baseUrl.'/manifest/video.mpd',
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }
}
