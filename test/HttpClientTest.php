<?php
// phpcs:ignoreFile
namespace PostHog\Test;

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

    public function testRetryAfterMillisecondsParsesSeconds(): void
    {
        $httpClient = new RetryAfterHttpClient("app.posthog.com");

        $this->assertSame(3000, $httpClient->parseRetryAfter(['Retry-After: 3']));
        $this->assertSame(0, $httpClient->parseRetryAfter(['Retry-After: 0']));
    }

    public function testRetryAfterMillisecondsParsesHttpDate(): void
    {
        $httpClient = new RetryAfterHttpClient("app.posthog.com");
        $retryAt = gmdate('D, d M Y H:i:s \G\M\T', time() + 2);

        $retryAfterMs = $httpClient->parseRetryAfter(['Retry-After: ' . $retryAt]);

        $this->assertNotNull($retryAfterMs);
        $this->assertGreaterThanOrEqual(0, $retryAfterMs);
        $this->assertLessThanOrEqual(2000, $retryAfterMs);
    }
}
