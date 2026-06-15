<?php
// phpcs:ignoreFile
namespace PostHog\Test;

require_once 'test/error_log_mock.php';

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PostHog\Client;
use PostHog\FlagDefinitionCacheProvider;
use PostHog\Test\Assets\MockedResponses;

class MockFlagDefinitionCacheProvider implements FlagDefinitionCacheProvider
{
    public ?array $cachedData = null;
    public bool $shouldFetch = true;
    public int $getCallCount = 0;
    public int $shouldFetchCallCount = 0;
    public int $onReceivedCallCount = 0;
    public int $shutdownCallCount = 0;
    public ?array $storedData = null;
    public ?\Throwable $shouldFetchError = null;
    public ?\Throwable $getError = null;
    public ?\Throwable $onReceivedError = null;
    public ?\Throwable $shutdownError = null;

    public function getFlagDefinitions(): ?array
    {
        $this->getCallCount++;
        if ($this->getError !== null) {
            throw $this->getError;
        }

        return $this->cachedData;
    }

    public function shouldFetchFlagDefinitions(): bool
    {
        $this->shouldFetchCallCount++;
        if ($this->shouldFetchError !== null) {
            throw $this->shouldFetchError;
        }

        return $this->shouldFetch;
    }

    public function onFlagDefinitionsReceived(array $data): void
    {
        $this->onReceivedCallCount++;
        if ($this->onReceivedError !== null) {
            throw $this->onReceivedError;
        }

        $this->storedData = $data;
    }

    public function shutdown(): void
    {
        $this->shutdownCallCount++;
        if ($this->shutdownError !== null) {
            throw $this->shutdownError;
        }
    }
}

class FlagDefinitionCacheProviderTest extends TestCase
{
    protected const FAKE_API_KEY = "random_key";

    public function setUp(): void
    {
        global $errorMessages;
        $errorMessages = [];
    }

    public function testUsesCachedDataWhenProviderSaysNotToFetch(): void
    {
        $provider = new MockFlagDefinitionCacheProvider();
        $provider->shouldFetch = false;
        $provider->cachedData = $this->sampleFlagDefinitionData();
        $httpClient = new MockedHttpClient(
            host: "app.posthog.com",
            flagEndpointResponse: MockedResponses::LOCAL_EVALUATION_REQUEST
        );

        $client = $this->createClient($provider, $httpClient);

        $this->assertSame(1, $provider->shouldFetchCallCount);
        $this->assertSame(1, $provider->getCallCount);
        $this->assertSame(0, $provider->onReceivedCallCount);
        $this->assertSame([], $httpClient->calls ?? []);
        $this->assertSame('beta-ui', $client->featureFlags[0]['key']);
        $this->assertSame(['0' => 'company'], $client->groupTypeMapping);
        $this->assertSame(['1' => ['type' => 'AND', 'values' => []]], $client->cohorts);
        $this->assertArrayHasKey('beta-ui', $client->featureFlagsByKey);
    }

    public function testStoresDefinitionsAfterProviderAllowsApiFetch(): void
    {
        $provider = new MockFlagDefinitionCacheProvider();
        $provider->shouldFetch = true;
        $httpClient = new MockedHttpClient(
            host: "app.posthog.com",
            flagEndpointResponse: $this->sampleFlagDefinitionData()
        );

        $client = $this->createClient($provider, $httpClient);

        $this->assertSame(1, $provider->shouldFetchCallCount);
        $this->assertSame(0, $provider->getCallCount);
        $this->assertSame(1, $provider->onReceivedCallCount);
        $this->assertCount(1, $httpClient->calls);
        $this->assertSame('beta-ui', $client->featureFlags[0]['key']);
        $this->assertSame('beta-ui', $provider->storedData['flags'][0]['key']);
        $this->assertSame(['0' => 'company'], $provider->storedData['group_type_mapping']);
        $this->assertSame(['1' => ['type' => 'AND', 'values' => []]], $provider->storedData['cohorts']);
    }

    public function testEmptyCacheFallsBackToApiWhenNoDefinitionsLoaded(): void
    {
        $provider = new MockFlagDefinitionCacheProvider();
        $provider->shouldFetch = false;
        $provider->cachedData = null;
        $httpClient = new MockedHttpClient(
            host: "app.posthog.com",
            flagEndpointResponse: $this->sampleFlagDefinitionData()
        );

        $client = $this->createClient($provider, $httpClient);

        $this->assertSame(1, $provider->getCallCount);
        $this->assertSame(1, $provider->onReceivedCallCount);
        $this->assertCount(1, $httpClient->calls);
        $this->assertSame('beta-ui', $client->featureFlags[0]['key']);
    }

    public function testProviderReadFailurePreservesPreviouslyLoadedDefinitions(): void
    {
        $provider = new MockFlagDefinitionCacheProvider();
        $provider->shouldFetch = true;
        $httpClient = new MockedHttpClient(
            host: "app.posthog.com",
            flagEndpointResponse: $this->sampleFlagDefinitionData()
        );
        $client = $this->createClient($provider, $httpClient);
        $this->assertSame('beta-ui', $client->featureFlags[0]['key']);

        $provider->shouldFetch = false;
        $provider->getError = new \RuntimeException('Redis read failed');
        $client->loadFlags();

        $this->assertSame(2, $provider->shouldFetchCallCount);
        $this->assertSame(1, $provider->getCallCount);
        $this->assertCount(1, $httpClient->calls);
        $this->assertSame('beta-ui', $client->featureFlags[0]['key']);
        $this->assertWarningContains('Cache provider read error: Redis read failed');
    }

    public function testProviderFetchDecisionFailureFailsSafeToDirectFetch(): void
    {
        $provider = new MockFlagDefinitionCacheProvider();
        $provider->shouldFetchError = new \RuntimeException('Lock acquisition failed');
        $httpClient = new MockedHttpClient(
            host: "app.posthog.com",
            flagEndpointResponse: $this->sampleFlagDefinitionData()
        );

        $client = $this->createClient($provider, $httpClient);

        $this->assertCount(1, $httpClient->calls);
        $this->assertSame('beta-ui', $client->featureFlags[0]['key']);
        $this->assertSame(1, $provider->onReceivedCallCount);
        $this->assertWarningContains('Cache provider fetch-decision error: Lock acquisition failed');
    }

    public function testProviderStoreFailureKeepsFetchedDefinitionsUsable(): void
    {
        $provider = new MockFlagDefinitionCacheProvider();
        $provider->shouldFetch = true;
        $provider->onReceivedError = new \RuntimeException('Redis write failed');
        $httpClient = new MockedHttpClient(
            host: "app.posthog.com",
            flagEndpointResponse: $this->sampleFlagDefinitionData()
        );

        $client = $this->createClient($provider, $httpClient);

        $this->assertSame('beta-ui', $client->featureFlags[0]['key']);
        $this->assertSame(1, $provider->onReceivedCallCount);
        $this->assertWarningContains('Cache provider store error: Redis write failed');
    }

    public function testProviderShutdownIsInvokedAndIsolatedFromSdkShutdown(): void
    {
        $provider = new MockFlagDefinitionCacheProvider();
        $provider->shutdownError = new \RuntimeException('Redis close failed');
        $httpClient = new MockedHttpClient(
            host: "app.posthog.com",
            flagEndpointResponse: $this->sampleFlagDefinitionData()
        );
        $client = $this->createClient($provider, $httpClient);

        $this->assertTrue($client->shutdown());

        $this->assertSame(1, $provider->shutdownCallCount);
        $this->assertWarningContains('Cache provider shutdown error: Redis close failed');
    }

    public function testMalformedProviderCacheDataDoesNotClearExistingDefinitions(): void
    {
        $provider = new MockFlagDefinitionCacheProvider();
        $provider->shouldFetch = true;
        $httpClient = new MockedHttpClient(
            host: "app.posthog.com",
            flagEndpointResponse: $this->sampleFlagDefinitionData()
        );
        $client = $this->createClient($provider, $httpClient);

        $provider->shouldFetch = false;
        $provider->cachedData = ['flags' => 'not an array'];
        $client->loadFlags();

        $this->assertSame('beta-ui', $client->featureFlags[0]['key']);
        $this->assertCount(1, $httpClient->calls);
        $this->assertWarningContains('Cache provider returned malformed flag definitions');
    }

    public function testCamelCaseGroupTypeMappingIsAcceptedFromCache(): void
    {
        $provider = new MockFlagDefinitionCacheProvider();
        $provider->shouldFetch = false;
        $provider->cachedData = [
            'flags' => [['key' => 'camel-flag', 'active' => true, 'filters' => []]],
            'groupTypeMapping' => ['0' => 'organization'],
            'cohorts' => [],
        ];
        $httpClient = new MockedHttpClient(host: "app.posthog.com");

        $client = $this->createClient($provider, $httpClient);

        $this->assertSame('camel-flag', $client->featureFlags[0]['key']);
        $this->assertSame(['0' => 'organization'], $client->groupTypeMapping);
        $this->assertSame([], $httpClient->calls ?? []);
    }

    public function testInvalidProviderOptionThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('flag_definition_cache_provider must implement');

        new Client(
            self::FAKE_API_KEY,
            ['flag_definition_cache_provider' => new \stdClass()],
            new MockedHttpClient(host: "app.posthog.com"),
            "test",
            false
        );
    }

    private function createClient(MockFlagDefinitionCacheProvider $provider, MockedHttpClient $httpClient): Client
    {
        return new Client(
            self::FAKE_API_KEY,
            ['flag_definition_cache_provider' => $provider],
            $httpClient,
            "test"
        );
    }

    private function sampleFlagDefinitionData(): array
    {
        return [
            'flags' => [
                [
                    'id' => 1,
                    'key' => 'beta-ui',
                    'active' => true,
                    'filters' => [
                        'groups' => [
                            [
                                'properties' => [],
                                'rollout_percentage' => 100,
                            ],
                        ],
                    ],
                ],
            ],
            'group_type_mapping' => ['0' => 'company'],
            'cohorts' => ['1' => ['type' => 'AND', 'values' => []]],
        ];
    }

    private function assertWarningContains(string $expected): void
    {
        global $errorMessages;
        $matched = false;
        foreach ($errorMessages as $message) {
            if (str_contains($message, $expected)) {
                $matched = true;
                break;
            }
        }

        $this->assertTrue($matched, "Expected warning containing '{$expected}', got: " . implode("\n", $errorMessages));
    }
}
