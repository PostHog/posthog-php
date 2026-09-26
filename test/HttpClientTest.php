<?php
// phpcs:ignoreFile
namespace PostHog\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PostHog\HttpClient;

class RetryAfterHttpClient extends HttpClient
{
    /** @param array<int, string> $headers */
    public function parseRetryAfter(array $headers): ?int
    {
        return $this->retryAfterMilliseconds($headers);
    }
}

class HttpClientTest extends TestCase
{
    public function testUnknownMockRouteCannotFallThroughToNetwork(): void
    {
        $client = new MockedHttpClient('unused.invalid');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Unexpected HTTP request in test: /unexpected');
        $client->sendRequest('/unexpected', null);
    }

    public function testMaskTokensInUrl(): void
    {
        $httpClient = new HttpClient("app.posthog.com");

        // Test masking token in middle of URL
        $url = 'https://example.com/api/flags?token=phc_abc123xyz789&send_cohorts';
        $result = $httpClient->maskTokensInUrl($url);
        $this->assertEquals('https://example.com/api/flags?token=[REDACTED]&send_cohorts', $result);

        // Test masking token at end of URL
        $url = 'https://example.com/api/flags?token=phc_abc123xyz789';
        $result = $httpClient->maskTokensInUrl($url);
        $this->assertEquals('https://example.com/api/flags?token=[REDACTED]', $result);

        // Test URL without token
        $url = 'https://example.com/api/flags?other=value';
        $result = $httpClient->maskTokensInUrl($url);
        $this->assertEquals('https://example.com/api/flags?other=value', $result);

        // Test short token - should still be redacted
        $url = 'https://example.com/api/flags?token=short';
        $result = $httpClient->maskTokensInUrl($url);
        $this->assertEquals('https://example.com/api/flags?token=[REDACTED]', $result);

        // Test empty token value
        $url = 'https://example.com/api/flags?token=&other=value';
        $result = $httpClient->maskTokensInUrl($url);
        $this->assertEquals('https://example.com/api/flags?token=&other=value', $result);
    }

    public static function retryAfterCases(): array
    {
        return [
            'absent' => [['Content-Type: application/json'], null],
            'empty' => [['Retry-After: '], null],
            'malformed' => [['Retry-After: not-a-date'], null],
            'past date' => [['Retry-After: Sat, 01 Jan 2000 00:00:00 GMT'], 0],
            'case insensitive' => [['retry-after: 2'], 2000],
        ];
    }

    #[DataProvider('retryAfterCases')]
    public function testRetryAfterEdgeCases(array $headers, ?int $expected): void
    {
        $this->assertSame($expected, (new RetryAfterHttpClient('unused'))->parseRetryAfter($headers));
    }

    public static function httpStatusCases(): array
    {
        return [
            'success' => [200, true, 1],
            'bad request' => [400, true, 1],
            'unauthorized' => [401, true, 1],
            'forbidden' => [403, true, 1],
            'too large' => [413, true, 1],
            'request timeout' => [408, true, 2],
            'rate limited' => [429, true, 2],
            'server error' => [500, true, 2],
            'unavailable' => [503, true, 2],
            'retries disabled' => [503, false, 1],
        ];
    }

    #[DataProvider('httpStatusCases')]
    public function testHttpResponsesAndRetryPolicy(int $status, bool $retry, int $attempts): void
    {
        $server = new LocalHttpServer([
            ['status' => $status, 'body' => 'first response', 'headers' => ['Retry-After' => '0']],
            ['status' => 200, 'body' => 'second response'],
        ]);
        try {
            global $errorMessages;
            $errorMessages = [];
            $client = new HttpClient($server->address(), useSsl: false, maximumBackoffDuration: 201, debug: true);
            $response = $client->sendRequest('/batch/', '{"batch":[]}', ['X-Test: preserved'], [
                'shouldRetry' => $retry, 'timeout' => 5000,
            ]);
            $this->assertSame($status === 200 ? [] : ['[PostHog][HttpClient] ' . $status], $errorMessages);
            $this->assertSame($attempts === 2 ? 200 : $status, $response->getResponseCode());
            $this->assertSame($attempts === 2 ? 'second response' : 'first response', $response->getResponse());
            $this->assertSame(0, $response->getCurlErrno());
            $requests = $server->requests();
            $this->assertCount($attempts, $requests);
            $this->assertSame('POST /batch/ HTTP/1.1', $requests[0]['requestLine']);
            $this->assertSame('{"batch":[]}', $requests[0]['body']);
            $this->assertSame('preserved', $requests[0]['headers']['x-test']);
            if ($attempts === 2) {
                $this->assertSame($requests[0], $requests[1]);
            }
        } finally {
            $server->stop();
        }
    }

    public function testConditionalGetPreservesEtagAndSeparatesResponseHeaders(): void
    {
        $server = new LocalHttpServer([
            ['body' => '{"flags":[]}', 'headers' => ['eTaG' => '"revision-1"']],
            ['status' => 304, 'body' => '', 'headers' => ['ETag' => '"revision-1"']],
        ]);
        try {
            $client = new HttpClient($server->address(), useSsl: false);
            $first = $client->sendRequest('/flags/definitions', null, [], ['includeEtag' => true]);
            $this->assertSame(200, $first->getResponseCode());
            $this->assertSame('{"flags":[]}', $first->getResponse());
            $this->assertSame('"revision-1"', $first->getEtag());
            $second = $client->sendRequest('/flags/definitions', null, [
                'If-None-Match: ' . $first->getEtag(),
            ], ['includeEtag' => true]);
            $this->assertSame(304, $second->getResponseCode());
            $this->assertSame('', $second->getResponse());
            $this->assertSame('"revision-1"', $second->getEtag());
            $requests = $server->requests();
            $this->assertCount(2, $requests);
            $this->assertSame('GET /flags/definitions HTTP/1.1', $requests[0]['requestLine']);
            $this->assertSame('', $requests[0]['body']);
            $this->assertSame('"revision-1"', $requests[1]['headers']['if-none-match']);
        } finally {
            $server->stop();
        }
    }

    public function testRetryAfterMillisecondsParsesSeconds(): void
    {
        $httpClient = new RetryAfterHttpClient("app.posthog.com");

        $this->assertSame(3000, $httpClient->parseRetryAfter(['Retry-After: 3']));
        $this->assertSame(0, $httpClient->parseRetryAfter(['Retry-After: 0']));
    }

    public function testRetryAfterMillisecondsParsesHttpDate(): void
    {
        $httpClient = new RetryAfterHttpClient("app.posthog.com");
        $before = time();
        $retryAt = gmdate('D, d M Y H:i:s \G\M\T', $before + 3600);

        $retryAfterMs = $httpClient->parseRetryAfter(['Retry-After: ' . $retryAt]);

        $this->assertNotNull($retryAfterMs);
        $this->assertGreaterThanOrEqual(($before + 3600 - time()) * 1000, $retryAfterMs);
        $this->assertLessThanOrEqual(3600000, $retryAfterMs);
    }
}
