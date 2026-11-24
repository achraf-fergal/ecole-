<?php

namespace App\Services\Learning;

use App\Models\LessonAsset;

class ExternalVideoUrlParser
{
    /**
     * Extract a provider ID from a strictly supported HTTPS URL.
     */
    public function parse(string $provider, string $url): ?string
    {
        $parts = parse_url($url);

        if (
            ! is_array($parts)
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || ! isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || (isset($parts['port']) && $parts['port'] !== 443)
            || isset($parts['fragment'])
        ) {
            return null;
        }

        $host = strtolower($parts['host']);
        $path = $parts['path'] ?? '';

        $assetId = match ($provider) {
            LessonAsset::VIDEO_PROVIDER_YOUTUBE => $this->youtubeId(
                $host,
                $path,
                $parts['query'] ?? ''
            ),
            LessonAsset::VIDEO_PROVIDER_GOOGLE_DRIVE => $this->googleDriveId(
                $host,
                $path
            ),
            default => null,
        };

        return is_string($assetId) && self::isValidAssetId($provider, $assetId)
            ? $assetId
            : null;
    }

    /** Validate a provider ID already stored in the asset record. */
    public static function isValidAssetId(string $provider, string $assetId): bool
    {
        return match ($provider) {
            LessonAsset::VIDEO_PROVIDER_YOUTUBE => preg_match(
                '/^[A-Za-z0-9_-]{11}$/D',
                $assetId
            ) === 1,
            LessonAsset::VIDEO_PROVIDER_GOOGLE_DRIVE => preg_match(
                '/^[A-Za-z0-9_-]{10,200}$/D',
                $assetId
            ) === 1,
            default => false,
        };
    }

    /** Confirm a stored provider ID still matches its validated source URL. */
    public static function isValidAssetReference(
        string $provider,
        string $url,
        string $assetId
    ): bool {
        return (new self)->parse($provider, $url) === $assetId;
    }

    private function youtubeId(
        string $host,
        string $path,
        string $query
    ): ?string {
        if ($host === 'youtu.be') {
            return preg_match(
                '#^/([A-Za-z0-9_-]{11})$#D',
                $path,
                $matches
            ) === 1
                ? $matches[1]
                : null;
        }

        if (! in_array($host, [
            'youtube.com',
            'www.youtube.com',
            'm.youtube.com',
        ], true)) {
            return null;
        }

        if ($path === '/watch') {
            $videoIds = [];

            foreach (explode('&', $query) as $queryPair) {
                [$key, $value] = array_pad(
                    explode('=', $queryPair, 2),
                    2,
                    ''
                );

                if (urldecode($key) === 'v') {
                    $videoIds[] = urldecode($value);
                }
            }

            return count($videoIds) === 1 ? $videoIds[0] : null;
        }

        if (preg_match(
            '#^/(?:embed|shorts|live)/([A-Za-z0-9_-]{11})$#D',
            $path,
            $matches
        ) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function googleDriveId(string $host, string $path): ?string
    {
        if ($host !== 'drive.google.com') {
            return null;
        }

        if (preg_match(
            '#^/file/d/([A-Za-z0-9_-]{10,200})/view$#D',
            $path,
            $matches
        ) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
