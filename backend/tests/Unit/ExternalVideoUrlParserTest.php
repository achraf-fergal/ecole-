<?php

namespace Tests\Unit;

use App\Models\LessonAsset;
use App\Services\Learning\ExternalVideoUrlParser;
use PHPUnit\Framework\TestCase;

class ExternalVideoUrlParserTest extends TestCase
{
    public function test_it_parses_supported_youtube_url_forms(): void
    {
        $parser = new ExternalVideoUrlParser;

        $urls = [
            'https://www.youtube.com/watch?v=M7lc1UVf-VE' => 'M7lc1UVf-VE',
            'https://youtu.be/M7lc1UVf-VE' => 'M7lc1UVf-VE',
            'https://www.youtube.com/embed/M7lc1UVf-VE' => 'M7lc1UVf-VE',
            'https://www.youtube.com/shorts/M7lc1UVf-VE' => 'M7lc1UVf-VE',
            'https://www.youtube.com/live/M7lc1UVf-VE' => 'M7lc1UVf-VE',
            'https://youtube.com/watch?feature=share&v=M7lc1UVf-VE&t=20s' => 'M7lc1UVf-VE',
        ];

        foreach ($urls as $url => $expectedId) {
            $this->assertSame(
                $expectedId,
                $parser->parse(LessonAsset::VIDEO_PROVIDER_YOUTUBE, $url),
                $url
            );
        }
    }

    public function test_it_rejects_unsupported_youtube_hosts_and_structures(): void
    {
        $parser = new ExternalVideoUrlParser;
        $urls = [
            'http://www.youtube.com/watch?v=M7lc1UVf-VE',
            'https://youtube.com.evil.test/watch?v=M7lc1UVf-VE',
            'https://youtube.com@evil.test/watch?v=M7lc1UVf-VE',
            'https://youtu.be.evil.test/M7lc1UVf-VE',
            'https://www.youtube.com/playlist?list=M7lc1UVf-VE',
            'https://www.youtube.com/watch?v=M7lc1UVf-VE&v=M7lc1UVf-VE',
            'https://youtu.be//M7lc1UVf-VE',
            'https://www.youtube.com/embed/invalid',
            'https://www.youtube.com/embed/M7lc1UVf-VE/extra',
        ];

        foreach ($urls as $url) {
            $this->assertNull(
                $parser->parse(LessonAsset::VIDEO_PROVIDER_YOUTUBE, $url),
                $url
            );
        }
    }

    public function test_it_parses_and_validates_google_drive_shared_video_links(): void
    {
        $parser = new ExternalVideoUrlParser;
        $fileId = '1AbCdEfGhIjKlMnOpQrStUvWxYz123456';

        $this->assertSame(
            $fileId,
            $parser->parse(
                LessonAsset::VIDEO_PROVIDER_GOOGLE_DRIVE,
                "https://drive.google.com/file/d/{$fileId}/view?usp=sharing"
            )
        );

        $this->assertTrue(
            ExternalVideoUrlParser::isValidAssetId(
                LessonAsset::VIDEO_PROVIDER_GOOGLE_DRIVE,
                $fileId
            )
        );
    }

    public function test_it_rejects_invalid_google_drive_urls(): void
    {
        $parser = new ExternalVideoUrlParser;
        $urls = [
            'http://drive.google.com/file/d/1AbCdEfGhIjKlMnOpQrStUvWxYz123456/view',
            'https://drive.google.com.evil.test/file/d/1AbCdEfGhIjKlMnOpQrStUvWxYz123456/view',
            'https://docs.google.com/file/d/1AbCdEfGhIjKlMnOpQrStUvWxYz123456/view',
            'https://drive.google.com/uc?id=1AbCdEfGhIjKlMnOpQrStUvWxYz123456',
            'https://drive.google.com/file/d/invalid/view',
            'https://drive.google.com/file/d/1AbCdEfGhIjKlMnOpQrStUvWxYz123456/edit',
        ];

        foreach ($urls as $url) {
            $this->assertNull(
                $parser->parse(
                    LessonAsset::VIDEO_PROVIDER_GOOGLE_DRIVE,
                    $url
                ),
                $url
            );
        }
    }

    public function test_it_does_not_parse_unsupported_providers(): void
    {
        $this->assertNull(
            (new ExternalVideoUrlParser)->parse(
                LessonAsset::VIDEO_PROVIDER_CLOUDFLARE_STREAM,
                'https://www.youtube.com/watch?v=M7lc1UVf-VE'
            )
        );
    }
}
