<?php

namespace PostHog\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PostHog\Consumer\ForkCurl;

class ForkCurlCompressionTest extends TestCase
{
    public static function compressionCases(): array
    {
        return ['plain JSON' => [false], 'gzip' => [true]];
    }

    #[DataProvider('compressionCases')]
    public function testEscapedCharactersSurviveDelivery(bool $compressed): void
    {
        $server = new LocalHttpServer();
        try {
            $consumer = new ForkCurl('test-key', [
                'host' => $server->address(),
                'ssl' => false,
                'debug' => true,
                'compress_request' => $compressed,
            ]);
            $message = [
                'event' => 'quoted "event" with an apostrophe\'',
                'distinct_id' => 'test-user',
                'properties' => [
                    'controls' => "line one\nline two\tvalue\rreturn",
                    'literal_backslashes' => '\\n \\t C:\\path\\file',
                    'format_string' => '%s %b %n',
                    'unicode' => 'café 🌍',
                ],
            ];

            self::assertTrue($consumer->flushBatch([$message]));
            $requests = $server->requests();
            self::assertCount(1, $requests);
            self::assertSame('POST /batch/ HTTP/1.1', $requests[0]['requestLine']);
            self::assertSame($compressed ? 'gzip' : null, $requests[0]['headers']['content-encoding'] ?? null);
            self::assertSame(
                ['batch' => [$message], 'api_key' => 'test-key'],
                json_decode($requests[0]['body'], true, 512, JSON_THROW_ON_ERROR)
            );
        } finally {
            $server->stop();
        }
    }
}
