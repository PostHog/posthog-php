<?php
// phpcs:ignoreFile
namespace PostHog\Test;

// comment out below to print all logs instead of failing tests
require_once 'test/error_log_mock.php';

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use PostHog\Client;
use PostHog\Test\Assets\MockedResponses;

class FeatureFlagCacheTest extends TestCase
{
    use ClockMockTrait;

    protected const FAKE_API_KEY = "random_key";

    public function setUp(): void
    {
        date_default_timezone_set("UTC");
        global $errorMessages;
        $errorMessages = [];
    }

    private function checkEmptyErrorLogs(): void
    {
        global $errorMessages;
        $this->assertTrue(empty($errorMessages), "Error logs are not empty: " . implode("\n", $errorMessages));
    }

    private function makeHttpClient(int $responseCode = 200): MockedHttpClient
    {
        return new MockedHttpClient(
            host: "app.posthog.com",
            flagEndpointResponse: MockedResponses::LOCAL_EVALUATION_REQUEST,
            flagEndpointEtag: '"etag-v1"',
            flagEndpointResponseCode: $responseCode
        );
    }

    private function definitionsCallCount(MockedHttpClient $http): int
    {
        $calls = $http->calls ?? [];
        return count(array_filter($calls, fn($c) => str_starts_with($c['path'], '/flags/definitions')));
    }

    public function testFirstClientFetchesAndPopulatesCache(): void
    {
        $cache = new ArrayCache();
        $http = $this->makeHttpClient();

        $this->executeAtFrozenDateTime(new DateTimeImmutable('2026-06-10 12:00:00'), function () use ($cache, $http) {
            $client = new Client(self::FAKE_API_KEY, ['feature_flags_cache' => $cache], $http, "test");
            $this->assertCount(1, $client->featureFlags);
            $this->assertEquals('person-flag', $client->featureFlags[0]['key']);
        });

        // Exactly one definitions fetch populated the cache (cache contents verified via the
        // second-client test, which restores the ETag from this entry).
        $this->assertEquals(1, $this->definitionsCallCount($http));
        $this->checkEmptyErrorLogs();
    }

    public function testSecondClientServesFromCacheWithoutFetching(): void
    {
        $cache = new ArrayCache();
        $http1 = $this->makeHttpClient();
        $http2 = $this->makeHttpClient();

        $this->executeAtFrozenDateTime(new DateTimeImmutable('2026-06-10 12:00:00'), function () use ($cache, $http1) {
            new Client(self::FAKE_API_KEY, ['feature_flags_cache' => $cache], $http1, "test");
        });

        // Second client a few seconds later (within the 30s freshness window): no HTTP fetch.
        $client2 = $this->executeAtFrozenDateTime(
            new DateTimeImmutable('2026-06-10 12:00:05'),
            fn() => new Client(self::FAKE_API_KEY, ['feature_flags_cache' => $cache], $http2, "test")
        );

        $this->assertEquals(0, $this->definitionsCallCount($http2), "Second client should not hit the API");
        $this->assertCount(1, $client2->featureFlags);
        $this->assertEquals('person-flag', $client2->featureFlags[0]['key']);
        $this->assertEquals('"etag-v1"', $client2->getFlagsEtag());
        $this->checkEmptyErrorLogs();
    }

    public function testRefetchesAfterFreshnessWindowExpires(): void
    {
        $cache = new ArrayCache();
        $http1 = $this->makeHttpClient();
        $http2 = $this->makeHttpClient();

        $this->executeAtFrozenDateTime(new DateTimeImmutable('2026-06-10 12:00:00'), function () use ($cache, $http1) {
            new Client(self::FAKE_API_KEY, ['feature_flags_cache' => $cache], $http1, "test");
        });

        // 31s later — past the 30s freshness window: the client refetches.
        $this->executeAtFrozenDateTime(
            new DateTimeImmutable('2026-06-10 12:00:31'),
            fn() => new Client(self::FAKE_API_KEY, ['feature_flags_cache' => $cache], $http2, "test")
        );

        $this->assertEquals(1, $this->definitionsCallCount($http2), "Stale entry should trigger a refetch");
        $this->checkEmptyErrorLogs();
    }

    public function testForceBypassesFreshCache(): void
    {
        $cache = new ArrayCache();
        $http1 = $this->makeHttpClient();
        $http2 = $this->makeHttpClient();

        $this->executeAtFrozenDateTime(new DateTimeImmutable('2026-06-10 12:00:00'), function () use ($cache, $http1) {
            new Client(self::FAKE_API_KEY, ['feature_flags_cache' => $cache], $http1, "test");
        });

        $this->executeAtFrozenDateTime(new DateTimeImmutable('2026-06-10 12:00:05'), function () use ($cache, $http2) {
            // Construction serves from fresh cache (no fetch), then force refetches.
            $client2 = new Client(self::FAKE_API_KEY, ['feature_flags_cache' => $cache], $http2, "test");
            $this->assertEquals(0, $this->definitionsCallCount($http2));
            $client2->loadFlags(true);
            $this->assertEquals(1, $this->definitionsCallCount($http2));
        });

        $this->checkEmptyErrorLogs();
    }

    public function testServesStaleCacheWhenApiIsDown(): void
    {
        $cache = new ArrayCache();
        $http1 = $this->makeHttpClient();
        $httpDown = $this->makeHttpClient(500); // API returns 500

        $this->executeAtFrozenDateTime(new DateTimeImmutable('2026-06-10 12:00:00'), function () use ($cache, $http1) {
            new Client(self::FAKE_API_KEY, ['feature_flags_cache' => $cache], $http1, "test");
        });

        // Past freshness window, API is down: definitions are still served from the stale cache.
        $client2 = $this->executeAtFrozenDateTime(
            new DateTimeImmutable('2026-06-10 12:01:00'),
            fn() => new Client(self::FAKE_API_KEY, ['feature_flags_cache' => $cache], $httpDown, "test")
        );

        $this->assertEquals(1, $this->definitionsCallCount($httpDown), "It should attempt a refetch");
        // ...but evaluation keeps working from the stale entry.
        $this->assertCount(1, $client2->featureFlags);
        $this->assertEquals('person-flag', $client2->featureFlags[0]['key']);
    }

    public function testFallsBackToHttpWhenCacheThrows(): void
    {
        $cache = new ArrayCache();
        $cache->throwOnAccess = true;
        $http = $this->makeHttpClient();

        $client = $this->executeAtFrozenDateTime(
            new DateTimeImmutable('2026-06-10 12:00:00'),
            fn() => new Client(self::FAKE_API_KEY, ['feature_flags_cache' => $cache], $http, "test")
        );

        // Cache get/set both throw; the client must still load definitions over HTTP, no fatal.
        $this->assertEquals(1, $this->definitionsCallCount($http));
        $this->assertCount(1, $client->featureFlags);
        $this->assertEquals('person-flag', $client->featureFlags[0]['key']);
        $this->checkEmptyErrorLogs();
    }

    public function testNoCacheConfiguredKeepsExistingBehavior(): void
    {
        $http = $this->makeHttpClient();
        $client = new Client(self::FAKE_API_KEY, [], $http, "test");

        $this->assertEquals(1, $this->definitionsCallCount($http));
        $this->assertCount(1, $client->featureFlags);
        $client->loadFlags();
        $this->assertEquals(2, $this->definitionsCallCount($http), "Without a cache every loadFlags hits the API");
        $this->checkEmptyErrorLogs();
    }
}
