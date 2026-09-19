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

    public function testProviderFetchDecisionFailureWithoutPersonalApiKeyDoesNotFetchDirectly(): void
    {
        $provider = new MockFlagDefinitionCacheProvider();
        $provider->shouldFetchError = new \RuntimeException('Lock acquisition failed');
        $httpClient = new MockedHttpClient(host: "app.posthog.com");
        $client = new Client(
            self::FAKE_API_KEY,
            ['flag_definition_cache_provider' => $provider],
            $httpClient,
            null,
            false
        );

        $client->loadFlags();

        $this->assertSame(1, $provider->shouldFetchCallCount);
        $this->assertSame(1, $provider->getCallCount);
        $this->assertSame([], $httpClient->calls ?? []);
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

    public function testIncompleteProviderCacheDataDoesNotClearExistingDefinitions(): void
    {
        $provider = new MockFlagDefinitionCacheProvider();
        $provider->shouldFetch = true;
        $httpClient = new MockedHttpClient(
            host: "app.posthog.com",
            flagEndpointResponse: $this->sampleFlagDefinitionData()
        );
        $client = $this->createClient($provider, $httpClient);

        $provider->shouldFetch = false;
        $provider->cachedData = [
            'flags' => [['key' => 'incomplete-flag', 'active' => true, 'filters' => []]],
        ];
        $client->loadFlags();

        $this->assertSame('beta-ui', $client->featureFlags[0]['key']);
        $this->assertSame(['0' => 'company'], $client->groupTypeMapping);
        $this->assertSame(['1' => ['type' => 'AND', 'values' => []]], $client->cohorts);
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

    public function testMinimalFlagCalledGatePersistsThroughProviderCache(): void
    {
        // Simulates a restart: a fresh client reads definitions (including the
        // minimal_flag_called_events gate) from the shared cache instead of the API and still
        // minimizes $feature_flag_called events.
        $provider = new MockFlagDefinitionCacheProvider();
        $provider->shouldFetch = false;
        $data = $this->sampleFlagDefinitionData();
        $data['flags'][0]['has_experiment'] = false;
        $data['minimal_flag_called_events'] = true;
        $provider->cachedData = $data;
        $httpClient = new MockedHttpClient(host: "app.posthog.com");

        $client = $this->createClient($provider, $httpClient);
        $this->assertTrue($client->getFeatureFlag('beta-ui', 'user-1'));
        $client->flush();

        $batchCall = null;
        foreach ($httpClient->calls as $call) {
            if (str_starts_with($call['path'], '/batch/')) {
                $batchCall = $call;
            }
        }
        $this->assertNotNull($batchCall);
        $properties = json_decode($batchCall['payload'], true)['batch'][0]['properties'];
        $this->assertFalse($properties['$feature_flag_has_experiment']);
        $this->assertTrue($properties['$is_server']);
        $this->assertArrayNotHasKey('$lib_consumer', $properties);
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

    public function testMatchingVersionSurvivesApiAndProviderRoundTrip(): void
    {
        $provider = new MockFlagDefinitionCacheProvider();
        $httpClient = new MockedHttpClient(
            host: 'app.posthog.com',
            flagEndpointResponse: $this->versionedDefinitions(2)
        );
        $client = $this->createClient($provider, $httpClient);
        $this->assertVersionedResults($client, false);
        $this->assertSame(2, $provider->storedData['property_matching_version']);

        $provider->shouldFetch = false;
        $provider->cachedData = $provider->storedData;
        $cacheHttp = new MockedHttpClient(host: 'app.posthog.com');
        $cachedClient = $this->createClient($provider, $cacheHttp);
        $this->assertVersionedResults($cachedClient, false);
        $this->assertSame([], $cacheHttp->calls ?? []);
        $this->assertOnlyDefinitionsRequests($httpClient);
    }

    public function testVersionOnlyApiReloadAnd304OrFailurePreserveSnapshot(): void
    {
        $provider = new MockFlagDefinitionCacheProvider();
        $httpClient = new MockedHttpClient(host: 'app.posthog.com');
        $httpClient->setFlagEndpointResponseQueue([
            ['response' => $this->versionedDefinitions(1), 'etag' => 'legacy'],
            ['response' => $this->versionedDefinitions(2), 'etag' => 'explicit'],
            ['responseCode' => 304],
            ['responseCode' => 500],
            ['response' => $this->versionedDefinitions(1)],
            ['response' => $this->versionedDefinitions(2)],
            ['response' => $this->versionedDefinitions(null)],
            ['response' => $this->versionedDefinitions(3)],
        ]);
        $client = $this->createClient($provider, $httpClient);
        foreach ([true, false, false, false, true, false, true, true] as $index => $expected) {
            if ($index > 0) {
                $client->loadFlags();
            }
            $this->assertVersionedResults($client, $expected);
            if ($index === 2 || $index === 3) {
                $this->assertSame('explicit', $client->getFlagsEtag());
                $this->assertSame(2, $provider->storedData['property_matching_version']);
            }
        }
        $this->assertOnlyDefinitionsRequests($httpClient);
    }

    public function testVersionOnlyCacheReloadAndReadFailure(): void
    {
        $provider = new MockFlagDefinitionCacheProvider();
        $provider->shouldFetch = false;
        $provider->cachedData = $this->versionedDefinitions(1);
        $httpClient = new MockedHttpClient(host: 'app.posthog.com');
        $client = $this->createClient($provider, $httpClient);
        foreach ([1, 2, 1, 2, null, 3] as $version) {
            $provider->cachedData = $this->versionedDefinitions($version);
            // Also cover the provider's supported camelCase projection.
            $provider->cachedData['groupTypeMapping'] = $provider->cachedData['group_type_mapping'];
            unset($provider->cachedData['group_type_mapping']);
            $client->loadFlags();
            $this->assertVersionedResults($client, $version !== 2);
            if ($version === 2) {
                $provider->getError = new \RuntimeException('read failed');
                $client->loadFlags();
                $this->assertVersionedResults($client, false);
                $provider->getError = null;
                $provider->cachedData = ['flags' => 'malformed'];
                $client->loadFlags();
                $this->assertVersionedResults($client, false);
                $provider->cachedData = null;
                $client->loadFlags();
                $this->assertVersionedResults($client, false);
            }
        }
        $this->assertSame([], $httpClient->calls ?? []);
    }

    public function testReloadDuringEvaluationDoesNotChangeItsDefinitionSnapshot(): void
    {
        foreach (['all', 'snapshot', 'single'] as $api) {
            $provider = new MockFlagDefinitionCacheProvider();
            $provider->shouldFetch = false;
            $definitions = $this->versionedDefinitions(2);
            $definitions['flags'][0]['filters']['groups'][0]['properties'] = [
                ['key' => 'reload', 'value' => '"trigger"', 'operator' => 'exact'],
                ['key' => 'value', 'value' => false, 'operator' => 'exact'],
            ];
            $provider->cachedData = $definitions;
            $httpClient = new MockedHttpClient(host: 'app.posthog.com');
            $client = $this->createClient($provider, $httpClient);
            $provider->cachedData = $this->versionedDefinitions(1);
            $reloadValue = new class ($client) implements \JsonSerializable {
                public function __construct(private Client $client)
                {
                }

                public function jsonSerialize(): mixed
                {
                    $this->client->loadFlags();
                    return 'trigger';
                }
            };
            $personProperties = ['value' => 'banana', 'reload' => $reloadValue];
            $groups = ['company' => 'acme'];
            $groupProperties = ['company' => ['value' => 'banana']];
            if ($api === 'all') {
                $results = $client->getAllFlags('user', $groups, $personProperties, $groupProperties);
                $this->assertSame(array_fill_keys(array_column($definitions['flags'], 'key'), false), $results);
            } elseif ($api === 'snapshot') {
                $snapshot = $client->evaluateFlags('user', $groups, $personProperties, $groupProperties);
                foreach (array_column($definitions['flags'], 'key') as $key) {
                    $this->assertFalse($snapshot->getFlag($key));
                }
            } else {
                set_error_handler(static fn ($errno) => $errno === E_USER_DEPRECATED, E_USER_DEPRECATED);
                try {
                    $this->assertFalse($client->getFeatureFlag(
                        'person', 'user', $groups, $personProperties, $groupProperties, false, false
                    ));
                } finally {
                    restore_error_handler();
                }
            }
            // The reentrant reload applies to the next call, not the evaluation in progress.
            $this->assertVersionedResults($client, true);
            $this->assertSame([], $httpClient->calls ?? []);
        }
    }

    public function testVersionedMissingPropertyStillFallsBackRemotely(): void
    {
        foreach ([1, 2] as $version) {
            $provider = new MockFlagDefinitionCacheProvider();
            $provider->shouldFetch = false;
            $provider->cachedData = $this->versionedDefinitions($version);
            $httpClient = new MockedHttpClient(
                host: 'app.posthog.com',
                flagsEndpointResponse: ['flags' => ['person' => ['enabled' => true, 'variant' => null]]]
            );
            $client = $this->createClient($provider, $httpClient);
            $local = $client->evaluateFlags('user', onlyEvaluateLocally: true, flagKeys: ['person']);
            $this->assertSame([], $local->getKeys());
            $this->assertSame([], $httpClient->calls ?? []);
            $remote = $client->evaluateFlags('user', flagKeys: ['person']);
            $this->assertTrue($remote->getFlag('person'));
            $this->assertCount(1, $httpClient->calls);
            $this->assertStringStartsWith('/flags/?', $httpClient->calls[0]['path']);
        }
    }

    private function assertVersionedResults(Client $client, bool $expected): void
    {
        $groups = ['company' => 'acme'];
        $properties = ['value' => 'banana'];
        $groupProperties = ['company' => $properties];
        $expectedFlags = array_fill_keys(['person', 'group', 'mixed', 'cohort', 'dependency', 'cohort-dependency'], $expected);
        $this->assertSame($expectedFlags, $client->getAllFlags('user', $groups, $properties, $groupProperties));
        $snapshot = $client->evaluateFlags('user', $groups, $properties, $groupProperties);
        foreach ($expectedFlags as $key => $value) {
            $this->assertSame($value, $snapshot->getFlag($key));
        }
        // Exercise the legacy single-flag entry point without its intentional deprecation warning.
        set_error_handler(static fn ($errno) => $errno === E_USER_DEPRECATED, E_USER_DEPRECATED);
        try {
            $this->assertSame($expected, $client->getFeatureFlag(
                'person', 'user', $groups, $properties, $groupProperties, false, false
            ));
        } finally {
            restore_error_handler();
        }
    }

    private function assertOnlyDefinitionsRequests(MockedHttpClient $httpClient): void
    {
        foreach ($httpClient->calls ?? [] as $call) {
            $this->assertStringStartsWith('/flags/definitions?', $call['path']);
        }
    }

    private function versionedDefinitions(?int $version): array
    {
        $leaf = ['key' => 'value', 'type' => 'person', 'value' => false, 'operator' => 'exact'];
        $cohort = ['key' => 'id', 'type' => 'cohort', 'value' => 1];
        $dependency = [
            'key' => 'person', 'type' => 'flag', 'value' => true,
            'operator' => 'flag_evaluates_to', 'dependency_chain' => ['person'],
        ];
        $makeFlag = static fn ($key, $property) => [
            'key' => $key, 'active' => true, 'version' => 2,
            'filters' => ['groups' => [['properties' => [$property], 'rollout_percentage' => 100]]],
        ];
        $flags = [
            $makeFlag('person', $leaf),
            $makeFlag('group', $leaf),
            $makeFlag('mixed', $leaf),
            $makeFlag('cohort', $cohort),
            $makeFlag('dependency', $dependency),
            $makeFlag('cohort-dependency', ['key' => 'id', 'type' => 'cohort', 'value' => 3]),
        ];
        $flags[1]['filters']['aggregation_group_type_index'] = 0;
        $flags[2]['filters']['groups'][0]['aggregation_group_type_index'] = 0;
        $data = [
            'flags' => $flags,
            'group_type_mapping' => ['0' => 'company'],
            'cohorts' => [
                '1' => ['type' => 'AND', 'values' => [
                    ['type' => 'OR', 'values' => [['key' => 'id', 'type' => 'cohort', 'value' => 2]]],
                ]],
                '2' => ['type' => 'AND', 'values' => [$leaf]],
                '3' => ['type' => 'AND', 'values' => [$dependency]],
            ],
        ];
        if ($version !== null) {
            $data['property_matching_version'] = $version;
        }
        return $data;
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
