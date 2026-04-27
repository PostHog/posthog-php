<?php

// phpcs:disable PSR1.Files.SideEffects
namespace PostHog\Test;

// comment out below to print all logs instead of failing tests
require_once 'test/error_log_mock.php';

use PHPUnit\Framework\TestCase;
use PostHog\Client;
use PostHog\EvaluatedFlagRecord;
use PostHog\FeatureFlagEvaluations;
use PostHog\PostHog;
use PostHog\Test\Assets\MockedResponses;

class FeatureFlagEvaluationsTest extends TestCase
{
    private const FAKE_API_KEY = 'random_key';

    private MockedHttpClient $http_client;
    private Client $client;

    public function setUp(): void
    {
        date_default_timezone_set('UTC');
        global $errorMessages;
        $errorMessages = [];
    }

    private function makeClient(
        $flagsEndpointResponse = MockedResponses::FLAGS_V2_RESPONSE,
        $localEvaluationResponse = [],
        ?string $personalApiKey = null,
        array $options = []
    ): void {
        $this->http_client = new MockedHttpClient(
            'app.posthog.com',
            flagEndpointResponse: $localEvaluationResponse,
            flagsEndpointResponse: $flagsEndpointResponse
        );
        $this->client = new Client(
            self::FAKE_API_KEY,
            $options,
            $this->http_client,
            $personalApiKey
        );
        PostHog::init(null, null, $this->client);
    }

    private function flagsRequestCount(): int
    {
        $count = 0;
        foreach ($this->http_client->calls ?? [] as $call) {
            if (str_starts_with($call['path'], '/flags/')) {
                $count++;
            }
        }
        return $count;
    }

    private function batchRequests(): array
    {
        $batches = [];
        foreach ($this->http_client->calls ?? [] as $call) {
            if (str_starts_with($call['path'], '/batch/')) {
                $batches[] = json_decode($call['payload'], true);
            }
        }
        return $batches;
    }

    private function makeRecord(
        string $key,
        bool $enabled,
        ?string $variant = null,
        mixed $payload = null,
        ?int $id = null,
        ?int $version = null,
        ?string $reason = null,
        bool $locallyEvaluated = false
    ): EvaluatedFlagRecord {
        return new EvaluatedFlagRecord(
            key: $key,
            enabled: $enabled,
            variant: $variant,
            payload: $payload,
            id: $id,
            version: $version,
            reason: $reason,
            locallyEvaluated: $locallyEvaluated,
        );
    }

    public function testEvaluateFlagsReturnsSnapshotAndMakesOneFlagsRequest(): void
    {
        $this->makeClient();
        $snapshot = PostHog::evaluateFlags('user-1');

        $this->assertInstanceOf(FeatureFlagEvaluations::class, $snapshot);
        $this->assertSame(1, $this->flagsRequestCount());
        $this->assertContains('simple-test', $snapshot->getKeys());
        $this->assertContains('multivariate-test', $snapshot->getKeys());
    }

    public function testNoFeatureFlagCalledEventsUntilAccess(): void
    {
        $this->makeClient();
        PostHog::evaluateFlags('user-1');
        PostHog::flush();

        $this->assertSame([], $this->batchRequests());
    }

    public function testIsEnabledFiresEventWithFullMetadata(): void
    {
        $this->makeClient();
        $snapshot = PostHog::evaluateFlags('user-1');

        $this->assertTrue($snapshot->isEnabled('simple-test'));
        PostHog::flush();

        $batches = $this->batchRequests();
        $this->assertCount(1, $batches);
        $event = $batches[0]['batch'][0];

        $this->assertSame('$feature_flag_called', $event['event']);
        $this->assertSame('user-1', $event['distinct_id']);
        $properties = $event['properties'];
        $this->assertSame('simple-test', $properties['$feature_flag']);
        $this->assertTrue($properties['$feature_flag_response']);
        $this->assertSame(6, $properties['$feature_flag_id']);
        $this->assertSame(1, $properties['$feature_flag_version']);
        $this->assertSame('Matched condition set 1', $properties['$feature_flag_reason']);
        $this->assertSame('98487c8a-287a-4451-a085-299cd76228dd', $properties['$feature_flag_request_id']);
        $this->assertArrayNotHasKey('locally_evaluated', $properties);
    }

    public function testGetFlagFiresEventOnFirstAccessDedupedOnSecond(): void
    {
        $this->makeClient();
        $snapshot = PostHog::evaluateFlags('user-1');

        $snapshot->getFlag('multivariate-test');
        $snapshot->getFlag('multivariate-test');
        PostHog::flush();

        $batches = $this->batchRequests();
        $this->assertCount(1, $batches);
        $this->assertCount(1, $batches[0]['batch']);
        $this->assertSame('multivariate-test', $batches[0]['batch'][0]['properties']['$feature_flag']);
        $this->assertSame('variant-value', $batches[0]['batch'][0]['properties']['$feature_flag_response']);
    }

    public function testGetFlagPayloadDoesNotFireEvent(): void
    {
        $this->makeClient();
        $snapshot = PostHog::evaluateFlags('user-1');

        $payload = $snapshot->getFlagPayload('json-payload');
        PostHog::flush();

        $this->assertSame(['key' => 'value'], $payload);
        $this->assertSame([], $this->batchRequests());
    }

    public function testUnknownKeyAccessRecordsFlagMissingError(): void
    {
        $this->makeClient();
        $snapshot = PostHog::evaluateFlags('user-1');

        $this->assertNull($snapshot->getFlag('does-not-exist'));
        $this->assertFalse($snapshot->isEnabled('does-not-exist'));
        PostHog::flush();

        $batches = $this->batchRequests();
        $this->assertCount(1, $batches);
        $properties = $batches[0]['batch'][0]['properties'];
        $this->assertSame('flag_missing', $properties['$feature_flag_error']);
    }

    public function testOnlyWarnsOnUnknownKeys(): void
    {
        $host = new FakeFlagEvaluationsHost();
        $snapshot = new FeatureFlagEvaluations(
            'user-1',
            ['flag-a' => $this->makeRecord('flag-a', true)],
            [],
            $host,
        );

        $filtered = $snapshot->only(['flag-a', 'unknown-flag']);

        $this->assertSame(['flag-a'], $filtered->getKeys());
        $this->assertCount(1, $host->warnings);
        $this->assertStringContainsString('unknown-flag', $host->warnings[0]);
    }

    public function testOnlyAccessedFallsBackAndWarnsWhenEmpty(): void
    {
        $host = new FakeFlagEvaluationsHost();
        $snapshot = new FeatureFlagEvaluations(
            'user-1',
            [
                'flag-a' => $this->makeRecord('flag-a', true),
                'flag-b' => $this->makeRecord('flag-b', false),
            ],
            [],
            $host,
        );

        $filtered = $snapshot->onlyAccessed();

        $this->assertEqualsCanonicalizing(['flag-a', 'flag-b'], $filtered->getKeys());
        $this->assertCount(1, $host->warnings);
    }

    public function testOnlyAccessedReturnsSubsetWhenAccessed(): void
    {
        $host = new FakeFlagEvaluationsHost();
        $snapshot = new FeatureFlagEvaluations(
            'user-1',
            [
                'flag-a' => $this->makeRecord('flag-a', true),
                'flag-b' => $this->makeRecord('flag-b', false),
            ],
            [],
            $host,
        );

        $snapshot->isEnabled('flag-a');
        $filtered = $snapshot->onlyAccessed();

        $this->assertSame(['flag-a'], $filtered->getKeys());
    }

    public function testFilteredSnapshotsDoNotBackPropagateAccess(): void
    {
        $host = new FakeFlagEvaluationsHost();
        $snapshot = new FeatureFlagEvaluations(
            'user-1',
            [
                'flag-a' => $this->makeRecord('flag-a', true),
                'flag-b' => $this->makeRecord('flag-b', true),
            ],
            [],
            $host,
        );

        $snapshot->isEnabled('flag-a');
        $filtered = $snapshot->onlyAccessed();

        // Touch flag-b on the child; the parent's accessed set should still be {flag-a}.
        $filtered->isEnabled('flag-b');

        $parentAccessed = $snapshot->onlyAccessed();
        $this->assertSame(['flag-a'], $parentAccessed->getKeys());
    }

    public function testCaptureFlagsAttachesFeaturePropertiesWithoutHttpRequest(): void
    {
        $this->makeClient();
        $snapshot = PostHog::evaluateFlags('user-1');

        $callsBefore = count($this->http_client->calls ?? []);

        PostHog::capture([
            'distinctId' => 'user-1',
            'event' => 'page_view',
            'flags' => $snapshot,
        ]);
        PostHog::flush();

        $batches = $this->batchRequests();
        $this->assertCount(1, $batches);
        $event = $batches[0]['batch'][0];
        $this->assertSame('page_view', $event['event']);
        $this->assertTrue($event['properties']['$feature/simple-test']);
        $this->assertSame('variant-value', $event['properties']['$feature/multivariate-test']);
        $this->assertContains('simple-test', $event['properties']['$active_feature_flags']);
        $this->assertNotContains('having_fun', $event['properties']['$active_feature_flags']);

        // Capture only added one /batch/ call; no extra /flags/ request.
        $newCalls = array_slice($this->http_client->calls, $callsBefore);
        foreach ($newCalls as $call) {
            $this->assertStringStartsNotWith('/flags/', $call['path']);
        }
    }

    public function testFlagKeysIsForwardedInRequestBody(): void
    {
        $this->makeClient();
        PostHog::evaluateFlags('user-1', flagKeys: ['simple-test', 'multivariate-test']);

        $flagsCall = null;
        foreach ($this->http_client->calls as $call) {
            if (str_starts_with($call['path'], '/flags/')) {
                $flagsCall = $call;
                break;
            }
        }

        $this->assertNotNull($flagsCall);
        $payload = json_decode($flagsCall['payload'], true);
        $this->assertSame(['simple-test', 'multivariate-test'], $payload['flag_keys_to_evaluate']);
    }

    public function testDisableGeoipIsForwardedInRequestBody(): void
    {
        $this->makeClient();
        PostHog::evaluateFlags('user-1', disableGeoip: true);

        $payload = null;
        foreach ($this->http_client->calls as $call) {
            if (str_starts_with($call['path'], '/flags/')) {
                $payload = json_decode($call['payload'], true);
                break;
            }
        }

        $this->assertNotNull($payload);
        $this->assertTrue($payload['geoip_disable']);
    }

    public function testEmptyDistinctIdSnapshotDoesNotFireEvents(): void
    {
        $this->makeClient();
        $snapshot = PostHog::evaluateFlags('');

        $this->assertSame([], $snapshot->getKeys());
        $this->assertSame(0, $this->flagsRequestCount());

        $snapshot->isEnabled('simple-test');
        $snapshot->getFlag('multivariate-test');
        PostHog::flush();

        $this->assertSame([], $this->batchRequests());
    }

    public function testLocallyEvaluatedFlagTagsLocallyEvaluatedAndReason(): void
    {
        $this->makeClient(
            personalApiKey: 'test-personal-key',
            localEvaluationResponse: MockedResponses::LOCAL_EVALUATION_REQUEST,
        );
        $snapshot = PostHog::evaluateFlags(
            'user-1',
            personProperties: ['region' => 'USA']
        );

        $this->assertTrue($snapshot->isEnabled('person-flag'));
        PostHog::flush();

        $batches = $this->batchRequests();
        $this->assertCount(1, $batches);
        $properties = $batches[0]['batch'][0]['properties'];
        $this->assertSame('person-flag', $properties['$feature_flag']);
        $this->assertTrue($properties['$feature_flag_response']);
        $this->assertSame('Evaluated locally', $properties['$feature_flag_reason']);
        $this->assertTrue($properties['locally_evaluated']);
        $this->assertSame(1, $properties['$feature_flag_id']);
        $this->assertArrayNotHasKey('$feature_flag_version', $properties);
        $this->assertArrayNotHasKey('$feature_flag_request_id', $properties);
    }

    public function testCaptureFlagsTakesPrecedenceOverSendFeatureFlags(): void
    {
        $this->makeClient();
        $host = new FakeFlagEvaluationsHost();
        $snapshot = new FeatureFlagEvaluations(
            'user-1',
            ['flag-a' => $this->makeRecord('flag-a', true)],
            [],
            $host,
        );

        PostHog::capture([
            'distinctId' => 'user-1',
            'event' => 'page_view',
            'flags' => $snapshot,
            'send_feature_flags' => true,
        ]);
        PostHog::flush();

        $batches = $this->batchRequests();
        $this->assertCount(1, $batches);
        $properties = $batches[0]['batch'][0]['properties'];

        // Only the snapshot's single flag is attached; send_feature_flags would have produced more.
        $this->assertTrue($properties['$feature/flag-a']);
        $this->assertSame(['flag-a'], $properties['$active_feature_flags']);
        $this->assertSame(0, $this->flagsRequestCount());
    }

    public function testFlagsArgumentNotSerializedIntoEventPayload(): void
    {
        $this->makeClient();
        $host = new FakeFlagEvaluationsHost();
        $snapshot = new FeatureFlagEvaluations(
            'user-1',
            ['flag-a' => $this->makeRecord('flag-a', true)],
            [],
            $host,
        );

        PostHog::capture([
            'distinctId' => 'user-1',
            'event' => 'page_view',
            'flags' => $snapshot,
        ]);
        PostHog::flush();

        $rawPayload = $this->http_client->calls[0]['payload'];
        $this->assertStringNotContainsString('FeatureFlagEvaluations', $rawPayload);
        $batches = $this->batchRequests();
        $this->assertArrayNotHasKey('flags', $batches[0]['batch'][0]);
    }

    public function testFeatureFlagsLogWarningsFalseSilencesFilterWarnings(): void
    {
        $host = new FakeFlagEvaluationsHost();
        $snapshot = new FeatureFlagEvaluations(
            'user-1',
            ['flag-a' => $this->makeRecord('flag-a', true)],
            [],
            $host,
            null,
            false,
        );

        $snapshot->only(['unknown']);
        $snapshot->onlyAccessed();

        $this->assertSame([], $host->warnings);
    }

    public function testSnapshotDedupesAcrossClientPaths(): void
    {
        $this->makeClient();
        $snapshot = PostHog::evaluateFlags('user-1');
        $snapshot->isEnabled('simple-test');

        // The single-flag path should be deduped against the snapshot's earlier event because
        // both share the Client's distinctIdsFeatureFlagsReported cache.
        PostHog::isFeatureEnabled('simple-test', 'user-1');
        PostHog::flush();

        $batches = $this->batchRequests();
        $this->assertCount(1, $batches);
        $this->assertCount(1, $batches[0]['batch']);
    }
}
