<?php

namespace App\Http\Controllers\Api\Learning;

use App\Http\Controllers\Controller;
use App\Models\LessonAsset;
use App\Services\Learning\CloudflareStreamService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CloudflareStreamWebhookController extends Controller
{
    public function __construct(
        protected CloudflareStreamService $cloudflareStream
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $secret = config('services.cloudflare_stream.webhook_secret');

        abort_unless(
            is_string($secret) && $secret !== '',
            503,
            'Video webhook verification is not configured.'
        );

        $rawBody = $request->getContent();
        $signature = $request->header('Webhook-Signature');

        abort_unless(
            is_string($signature)
                && $this->hasValidSignature($signature, $rawBody, $secret),
            403,
            'Invalid video webhook signature.'
        );

        $payload = json_decode($rawBody, true);

        abort_unless(is_array($payload), 400, 'Invalid video webhook payload.');

        $uid = $payload['uid'] ?? null;

        abort_unless(
            is_string($uid)
                && preg_match('/^[A-Za-z0-9_-]{16,128}$/D', $uid) === 1,
            400,
            'Invalid video webhook payload.'
        );

        $asset = LessonAsset::query()
            ->where('asset_type', LessonAsset::TYPE_VIDEO)
            ->where('video_provider', 'cloudflare_stream')
            ->where('provider_asset_id', $uid)
            ->first();

        if (! $asset) {
            return response()->json([
                'message' => 'Webhook received.',
                'data' => ['processed' => false],
            ], 202);
        }

        // Fetch canonical state instead of trusting an old or incomplete event payload.
        $details = $this->cloudflareStream->getVideoDetails($uid);
        $result = $this->cloudflareStream->synchronizeAssetStatus($asset, $details);

        return response()->json([
            'message' => 'Webhook received.',
            'data' => [
                'processed' => true,
                'status' => $result['status'],
            ],
        ]);
    }

    private function hasValidSignature(
        string $header,
        string $rawBody,
        string $secret
    ): bool {
        $fields = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);

            if ($key !== null && $value !== null) {
                $fields[$key][] = $value;
            }
        }

        $timestamp = $fields['time'][0] ?? null;
        $signatures = $fields['sig1'] ?? [];

        if (
            ! is_string($timestamp)
            || ! ctype_digit($timestamp)
            || $signatures === []
        ) {
            return false;
        }

        $sentAt = (int) $timestamp;
        $now = now()->timestamp;
        $tolerance = max(
            30,
            min(3600, (int) config(
                'services.cloudflare_stream.webhook_tolerance_seconds',
                300
            ))
        );

        if (abs($now - $sentAt) > $tolerance) {
            return false;
        }

        $expected = hash_hmac(
            'sha256',
            $timestamp.'.'.$rawBody,
            $secret
        );

        foreach ($signatures as $candidate) {
            if (
                is_string($candidate)
                && preg_match('/^[a-fA-F0-9]{64}$/D', $candidate) === 1
                && hash_equals($expected, strtolower($candidate))
            ) {
                return true;
            }
        }

        return false;
    }
}
