<?php

// phpcs:disable PSR1.Files.SideEffects
namespace PostHog\Test;

// comment out below to print all logs instead of failing tests
require_once 'test/error_log_mock.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use PostHog\Client;
use PostHog\EvaluatedFlagRecord;
use PostHog\FeatureFlagEvaluations;
use PostHog\PostHog;
use PostHog\Test\Assets\MockedResponses;
use PostHog\UnresolvedFlagReason;

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
        array $options = [],
        int $flagsEndpointResponseCode = 200
    ): void {
        $this->http_client = new MockedHttpClient(
            'app.posthog.com',
            flagEndpointResponse: $localEvaluationResponse,
            flagsEndpointResponse: $flagsEndpointResponse,
            flagsEndpointResponseCode: $flagsEndpointResponseCode
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
        // Counts the runtime /flags endpoint only; the /flags/definitions local-evaluation
        // poller hits a different path and shouldn't be conflated with per-request evaluation.
        $count = 0;
        foreach ($this->http_client->calls ?? [] as $call) {
            if (str_starts_with($call['path'], '/flags/?')) {
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
        $this->assertTrue($snapshot->isEnabled('simple-test'));
        $this->assertFalse($snapshot->isEnabled('having_fun'));
        $this->assertSame('variant-value', $snapshot->getFlag('multivariate-test'));
        $this->assertSame(1, $this->flagsRequestCount());
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
        $this->assertFalse($properties['locally_evaluated']);
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
        $this->assertSame([], $snapshot->onlyAccessed()->getKeys());
        $this->assertSame(1, $this->flagsRequestCount());
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

    public function testOnlyAccessedReturnsEmptyWhenNoFlagsAccessed(): void
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

        $this->assertSame([], $filtered->getKeys());
        $this->assertSame([], $host->warnings);
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

    public function testEmptyFlagKeysReturnsEmptySnapshotWithoutEvaluationRequests(): void
    {
        $this->makeClient();
        $callsBefore = count($this->http_client->calls ?? []);

        $snapshot = PostHog::evaluateFlags('user-1', flagKeys: []);

        $this->assertInstanceOf(FeatureFlagEvaluations::class, $snapshot);
        $this->assertSame([], $snapshot->getKeys());
        $this->assertCount($callsBefore, $this->http_client->calls ?? []);
        $this->assertSame(0, $this->flagsRequestCount());
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
        $this->assertArrayNotHasKey('$feature_flag_evaluated_at', $properties);
        // Local definition doesn't report has_experiment, so the property is omitted.
        $this->assertArrayNotHasKey('$feature_flag_has_experiment', $properties);
    }

    public function testHasExperimentFromRemoteMetadataPropagatesToEvent(): void
    {
        $response = MockedResponses::FLAGS_V2_RESPONSE;
        $response['flags']['simple-test']['metadata']['has_experiment'] = true;
        $response['flags']['multivariate-test']['metadata']['has_experiment'] = false;
        // having_fun's metadata omits has_experiment, covering older deployments.
        $this->makeClient(flagsEndpointResponse: $response);

        $snapshot = PostHog::evaluateFlags('user-1');
        $snapshot->isEnabled('simple-test');
        $snapshot->getFlag('multivariate-test');
        $snapshot->isEnabled('having_fun');
        PostHog::flush();

        $batches = $this->batchRequests();
        $this->assertCount(1, $batches);
        $propertiesByFlag = [];
        foreach ($batches[0]['batch'] as $event) {
            $propertiesByFlag[$event['properties']['$feature_flag']] = $event['properties'];
        }

        $this->assertTrue($propertiesByFlag['simple-test']['$feature_flag_has_experiment']);
        $this->assertFalse($propertiesByFlag['multivariate-test']['$feature_flag_has_experiment']);
        // Unreported has_experiment omits the property rather than defaulting it.
        $this->assertArrayNotHasKey('$feature_flag_has_experiment', $propertiesByFlag['having_fun']);
    }

    public function testHasExperimentFromLocalDefinitionPropagatesToEvent(): void
    {
        $localResponse = MockedResponses::LOCAL_EVALUATION_REQUEST;
        $localResponse['flags'][0]['has_experiment'] = true;
        $this->makeClient(
            personalApiKey: 'test-personal-key',
            localEvaluationResponse: $localResponse,
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
        $this->assertTrue($properties['locally_evaluated']);
        $this->assertTrue($properties['$feature_flag_has_experiment']);
    }

    public function testMinimalFlagCalledEventKeepsLocallyEvaluatedUnderGate(): void
    {
        $localResponse = MockedResponses::LOCAL_EVALUATION_REQUEST;
        $localResponse['minimal_flag_called_events'] = true;
        $localResponse['flags'][0]['has_experiment'] = false;
        $this->makeClient(
            personalApiKey: 'test-personal-key',
            localEvaluationResponse: $localResponse,
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
        ksort($properties);
        $expected = [
            '$feature_flag' => 'person-flag',
            '$feature_flag_response' => true,
            '$feature_flag_has_experiment' => false,
            '$feature_flag_id' => 1,
            '$feature_flag_reason' => 'Evaluated locally',
            '$groups' => [],
            '$lib' => 'posthog-php',
            '$lib_version' => PostHog::VERSION,
            '$is_server' => true,
            'locally_evaluated' => true,
        ];
        ksort($expected);
        $this->assertSame($expected, $properties);
    }

    public function testLocalEvaluationSkipsRemoteFlagsRequestWhenAllResolved(): void
    {
        // When local definitions cover every flag and they all evaluate without inconclusive
        // results, evaluateFlags() should skip the /flags request entirely.
        $this->makeClient(
            personalApiKey: 'test-personal-key',
            localEvaluationResponse: MockedResponses::LOCAL_EVALUATION_REQUEST,
        );
        $snapshot = PostHog::evaluateFlags('user-1', personProperties: ['region' => 'USA']);

        $this->assertSame(['person-flag'], $snapshot->getKeys());
        $this->assertTrue($snapshot->isEnabled('person-flag'));
        $this->assertSame(0, $this->flagsRequestCount());
    }

    #[DataProvider('numericLocalEvaluationProvider')]
    public function testNumericOrderingLocalEvaluationAndFallback(
        string $operator,
        mixed $filter,
        array $personProperties,
        bool|string $expected,
        bool $fallback
    ): void {
        $this->makeClient(
            personalApiKey: 'test-personal-key',
            flagsEndpointResponse: [
                'flags' => ['numeric-flag' => ['enabled' => true, 'variant' => 'remote-value']],
            ],
            localEvaluationResponse: [
                'flags' => [[
                    'id' => 1,
                    'key' => 'numeric-flag',
                    'active' => true,
                    'filters' => ['groups' => [[
                        'properties' => [[
                            'key' => 'number',
                            'type' => 'person',
                            'operator' => $operator,
                            'value' => $filter,
                        ]],
                        'rollout_percentage' => 100,
                    ]]],
                ]],
                'group_type_mapping' => [],
                'cohorts' => [],
            ],
        );

        $snapshot = $this->client->evaluateFlags('user-1', personProperties: $personProperties);

        $this->assertSame($expected, $snapshot->getFlag('numeric-flag'));
        $this->assertSame($fallback ? 1 : 0, $this->flagsRequestCount());

        if ($fallback) {
            $localSnapshot = $this->client->evaluateFlags(
                'user-1',
                personProperties: $personProperties,
                onlyEvaluateLocally: true
            );
            $this->assertSame([], $localSnapshot->getKeys());
            $this->assertSame(1, $this->flagsRequestCount());
        }
    }

    public static function numericLocalEvaluationProvider(): iterable
    {
        yield 'reported gt numeric filter' => ['gt', 2, ['number' => '10'], true, false];
        yield 'reported lt numeric filter' => ['lt', 30, ['number' => '100'], false, false];
        yield 'reported gte string filter' => ['gte', '5', ['number' => '10'], true, false];
        yield 'reported lte string filter' => ['lte', '5', ['number' => '10'], false, false];
        yield 'numeric actual and string filter' => ['gt', '2', ['number' => 10], true, false];
        yield 'signed exponent' => ['lt', '-.5', ['number' => '-1e1'], true, false];
        $invalidOperands = ['10px', ' 10 ', "10\n", '1_0', 'NaN', 'Infinity', '1e309', null, true, [], (object) []];
        foreach ($invalidOperands as $index => $invalid) {
            yield "invalid actual $index" => ['gt', '2', ['number' => $invalid], false, false];
            yield "invalid filter $index" => ['gt', $invalid, ['number' => '10'], 'remote-value', true];
        }
        yield 'missing actual' => ['gt', '2', [], 'remote-value', true];
        yield 'invalid filter and actual' => ['gt', 'bad', ['number' => null], 'remote-value', true];
    }

    #[DataProvider('regexCompatibilityFallbackProvider')]
    public function testRegexCompatibilityLocalEvaluationAndFallback(
        string $operator,
        string $mode,
        string $pattern,
        array $properties,
        bool $fallback,
        bool $matched
    ): void {
        $leaf = ['key' => 'k', 'type' => 'person', 'operator' => $operator, 'value' => $pattern];
        $cohorts = [];
        $condition = $leaf;
        if ($mode !== 'direct') {
            $leaf['negation'] = $mode === 'negated cohort leaf';
            $cohorts['1'] = ['type' => 'AND', 'values' => [$leaf]];
            $condition = ['key' => 'id', 'type' => 'cohort', 'value' => '1'];
        }
        $this->makeClient(
            personalApiKey: 'test-personal-key',
            flagsEndpointResponse: [
                'flags' => ['regex-flag' => ['enabled' => true, 'variant' => 'remote-value']],
            ],
            localEvaluationResponse: [
                'flags' => [[
                    'id' => 1,
                    'key' => 'regex-flag',
                    'active' => true,
                    'filters' => ['groups' => [['properties' => [$condition], 'rollout_percentage' => 100]]],
                ]],
                'group_type_mapping' => [],
                'cohorts' => $cohorts,
            ],
        );
        $expected = $operator === 'regex' ? $matched : !$matched;
        if ($mode === 'negated cohort leaf') {
            $expected = !$expected;
        }
        $snapshot = $this->client->evaluateFlags('user-1', personProperties: $properties);
        $this->assertSame($fallback ? 'remote-value' : $expected, $snapshot->getFlag('regex-flag'));
        $this->assertSame($fallback ? 1 : 0, $this->flagsRequestCount());

        $localSnapshot = $this->client->evaluateFlags(
            'user-1',
            personProperties: $properties,
            onlyEvaluateLocally: true
        );
        $this->assertSame($fallback ? [] : ['regex-flag'], $localSnapshot->getKeys());
        $this->assertSame($fallback ? 1 : 0, $this->flagsRequestCount());
    }

    public static function regexCompatibilityFallbackProvider(): iterable
    {
        $cases = [
            'unbounded lookbehind' => ['(?<=a+)b', ['k' => 'aab'], true, true],
            'POSIX class' => ['^[[:alpha:]]+$', ['k' => 'é'], true, false],
            'set intersection' => ['^[a-z&&[^aeiou]]+$', ['k' => 'bcdf'], true, true],
            'word boundary budget' => ['.*\\bbot\\b.*', ['k' => str_repeat('a', 140)], true, false],
            'execution failure' => ['x', ['k' => "\xff"], true, false],
            'missing property control' => ['x', [], true, false],
            'final newline' => ['^foo$', ['k' => "foo\n"], false, false],
            'portable match control' => ['^pro$', ['k' => 'pro'], false, true],
            'portable mismatch control' => ['^pro$', ['k' => 'free'], false, false],
            'Unicode scalar control' => ['^.$', ['k' => '😀'], false, true],
        ];
        foreach (['\\w', '\\W', '[\\w]', '[\\W]', '[^\\w]', '[^\\W]'] as $atom) {
            foreach (["\u{200C}", "\u{200D}", "\u{0903}", "\u{20DD}", "\u{00B2}", "\u{24B6}"] as $index => $input) {
                $cases["uncertain word class $atom $index"] = ['^' . $atom . '$', ['k' => $input], true, false];
            }
        }
        foreach (['\\w' => true, '\\W' => false, '[\\w]' => true, '[^\\w]' => false] as $atom => $matched) {
            foreach (['a_1', 'éα', '١𝟙'] as $index => $input) {
                $cases["safe word class $atom $index"] = ['^' . $atom . '+$', ['k' => $input], false, $matched];
            }
        }
        $cases['join control without word class'] = ['^.$', ['k' => "\u{200C}"], false, true];
        $cases['escaped word marker'] = ['^\\\\w.$', ['k' => "\\w\u{200C}"], false, true];
        foreach (['regex', 'not_regex'] as $operator) {
            foreach (['direct', 'cohort', 'negated cohort leaf'] as $mode) {
                foreach ($cases as $name => $case) {
                    yield "$operator $mode $name" => [$operator, $mode, ...$case];
                }
            }
        }
    }

    public function testLocalEvaluationResolvesPayloads(): void
    {
        // Local definitions carry `filters.payloads`, keyed by "true" for boolean flags and by
        // variant key for multivariate ones, so a locally evaluated flag exposes its payload
        // without a /flags request.
        $this->makeClient(
            personalApiKey: 'test-personal-key',
            localEvaluationResponse: [
                'flags' => [
                    [
                        'id' => 1,
                        'key' => 'boolean-payload',
                        'active' => true,
                        'filters' => [
                            'groups' => [['properties' => [], 'rollout_percentage' => 100]],
                            'payloads' => ['true' => '{"tiers": [33, 66, 100]}'],
                        ],
                    ],
                    [
                        'id' => 2,
                        'key' => 'variant-payload',
                        'active' => true,
                        'filters' => [
                            'groups' => [['properties' => [], 'rollout_percentage' => 100]],
                            'multivariate' => ['variants' => [
                                ['key' => 'control', 'rollout_percentage' => 0],
                                ['key' => 'test', 'rollout_percentage' => 100],
                            ]],
                            'payloads' => ['control' => '"a"', 'test' => ['already' => 'decoded']],
                        ],
                    ],
                    [
                        'id' => 3,
                        'key' => 'off-with-payload',
                        'active' => false,
                        'filters' => [
                            'groups' => [['properties' => [], 'rollout_percentage' => 100]],
                            'payloads' => ['true' => '1'],
                        ],
                    ],
                ],
                'group_type_mapping' => [],
                'cohorts' => [],
            ],
        );

        $snapshot = PostHog::evaluateFlags('user-1');

        $this->assertSame(['tiers' => [33, 66, 100]], $snapshot->getFlagPayload('boolean-payload'));
        $this->assertSame('test', $snapshot->getFlag('variant-payload'));
        $this->assertSame(['already' => 'decoded'], $snapshot->getFlagPayload('variant-payload'));
        $this->assertNull($snapshot->getFlagPayload('off-with-payload'));
        $this->assertSame(0, $this->flagsRequestCount());
    }

    #[DataProvider('falseFlagPayloadProvider')]
    #[IgnoreDeprecations]
    public function testLocalFalseFlagResolvesPayload(mixed $rawPayload, mixed $expectedPayload): void
    {
        $this->makeClient(
            personalApiKey: 'test-personal-key',
            localEvaluationResponse: [
                'flags' => [[
                    'id' => 1,
                    'key' => 'false-payload',
                    'active' => true,
                    'filters' => [
                        'groups' => [['properties' => [], 'rollout_percentage' => 0]],
                        'payloads' => ['true' => '123', 'false' => $rawPayload],
                    ],
                ]],
                'group_type_mapping' => [],
                'cohorts' => [],
            ],
        );

        $snapshot = $this->client->evaluateFlags('user-1');

        $this->assertFalse($snapshot->getFlag('false-payload'));
        $this->assertSame($expectedPayload, $snapshot->getFlagPayload('false-payload'));
        $this->assertSame(
            $expectedPayload,
            $this->client->getFeatureFlagPayload('false-payload', 'user-1')
        );
        $this->assertSame(0, $this->flagsRequestCount());
    }

    public static function falseFlagPayloadProvider(): array
    {
        return [
            'JSON false' => ['false', false],
            'pre-decoded false' => [false, false],
            'number' => ['400', 400],
            'object' => ['{"fallback":true}', ['fallback' => true]],
            'missing payload' => [null, null],
        ];
    }

    public function testRemoteEvaluatedAtPropagatesToEvent(): void
    {
        $response = MockedResponses::FLAGS_V2_RESPONSE;
        $response['evaluatedAt'] = 1714435200;
        $this->makeClient(flagsEndpointResponse: $response);

        $snapshot = PostHog::evaluateFlags('user-1');
        $snapshot->isEnabled('simple-test');
        PostHog::flush();

        $batches = $this->batchRequests();
        $this->assertCount(1, $batches);
        $this->assertSame(
            1714435200,
            $batches[0]['batch'][0]['properties']['$feature_flag_evaluated_at']
        );
    }

    public function testMissingFlagResponseIsNullNotFalse(): void
    {
        $this->makeClient();
        $snapshot = PostHog::evaluateFlags('user-1');
        $snapshot->isEnabled('does-not-exist');
        PostHog::flush();

        $batches = $this->batchRequests();
        $this->assertCount(1, $batches);
        $properties = $batches[0]['batch'][0]['properties'];
        $this->assertNull($properties['$feature_flag_response']);
        $this->assertFalse($properties['locally_evaluated']);
        // Missing flags have no server-reported has_experiment, so the property is omitted.
        $this->assertArrayNotHasKey('$feature_flag_has_experiment', $properties);
    }

    public function testRemotePayloadHandlesPreDecodedValue(): void
    {
        // Some middleware may transparently decode JSON payloads before they reach the SDK; the
        // snapshot should accept the value as-is rather than json_decode-ing a non-string.
        $response = MockedResponses::FLAGS_V2_RESPONSE;
        $response['flags']['json-payload']['metadata']['payload'] = ['already' => 'decoded'];
        $this->makeClient(flagsEndpointResponse: $response);

        $snapshot = PostHog::evaluateFlags('user-1');
        $this->assertSame(['already' => 'decoded'], $snapshot->getFlagPayload('json-payload'));
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

    public function testCaptureWarnsWhenBothFlagsAndSendFeatureFlagsSet(): void
    {
        global $errorMessages;
        $errorMessages = [];

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

        $warned = false;
        foreach ($errorMessages as $message) {
            if (str_contains($message, 'Both `flags` and `send_feature_flags`')) {
                $warned = true;
                break;
            }
        }
        $this->assertTrue($warned, 'Expected warning when both `flags` and `send_feature_flags` are set');

        // No extra /flags request.
        $this->assertSame(0, $this->flagsRequestCount());
    }

    public function testErrorsWhileComputingFlagsPropagatesToEvent(): void
    {
        $errorResponse = MockedResponses::FLAGS_V2_RESPONSE;
        $errorResponse['errorsWhileComputingFlags'] = true;
        $this->makeClient(flagsEndpointResponse: $errorResponse);

        $snapshot = PostHog::evaluateFlags('user-1');
        $snapshot->isEnabled('simple-test');
        PostHog::flush();

        $batches = $this->batchRequests();
        $this->assertCount(1, $batches);
        $this->assertSame(
            'errors_while_computing_flags',
            $batches[0]['batch'][0]['properties']['$feature_flag_error']
        );
    }

    public function testErrorsWhileComputingFlagsCombinesWithFlagMissing(): void
    {
        $errorResponse = MockedResponses::FLAGS_V2_RESPONSE;
        $errorResponse['errorsWhileComputingFlags'] = true;
        $this->makeClient(flagsEndpointResponse: $errorResponse);

        $snapshot = PostHog::evaluateFlags('user-1');
        $snapshot->isEnabled('does-not-exist');
        PostHog::flush();

        $batches = $this->batchRequests();
        $this->assertCount(1, $batches);
        $this->assertSame(
            'errors_while_computing_flags,flag_missing',
            $batches[0]['batch'][0]['properties']['$feature_flag_error']
        );
    }

    public function testSnapshotDedupesAcrossClientPaths(): void
    {
        $this->makeClient();
        $snapshot = PostHog::evaluateFlags('user-1');
        $snapshot->isEnabled('simple-test');

        // The single-flag path should be deduped against the snapshot's earlier event because
        // both share the Client's distinctIdsFeatureFlagsReported cache. The legacy single-flag
        // method also emits a deprecation; we suppress it here so the test focuses on dedup.
        $this->captureDeprecations(fn() => PostHog::isFeatureEnabled('simple-test', 'user-1'));
        PostHog::flush();

        $batches = $this->batchRequests();
        $this->assertCount(1, $batches);
        $this->assertCount(1, $batches[0]['batch']);
    }

    /**
     * @return array<string, array{list<mixed>, list<mixed>}>
     */
    public static function featureFlagCalledResponseDedupCases(): array
    {
        return [
            'true and false' => [[true, false, true, false], [true, false]],
            'false and true' => [[false, true, false, true], [false, true]],
            'null and variant' => [[null, 'variant-a', null, 'variant-a'], [null, 'variant-a']],
            'boolean and matching string' => [[true, 'true', true, 'true'], [true, 'true']],
        ];
    }

    /**
     * @param list<mixed> $responses
     * @param list<mixed> $expectedResponses
     */
    #[DataProvider('featureFlagCalledResponseDedupCases')]
    public function testFeatureFlagCalledDedupesByResponseValue(array $responses, array $expectedResponses): void
    {
        $this->makeClient();

        foreach ($responses as $response) {
            $this->client->captureFlagCalledIfNeeded(
                'user-1',
                'changing-flag',
                $response,
                ['$feature_flag' => 'changing-flag', '$feature_flag_response' => $response]
            );
        }

        PostHog::flush();

        $events = [];
        foreach ($this->batchRequests() as $batch) {
            foreach ($batch['batch'] as $event) {
                if (
                    $event['event'] === '$feature_flag_called'
                    && ($event['properties']['$feature_flag'] ?? null) === 'changing-flag'
                ) {
                    $events[] = $event;
                }
            }
        }

        $this->assertCount(count($expectedResponses), $events, 'unexpected $feature_flag_called event count');
        $this->assertSame(
            $expectedResponses,
            array_map(fn($event) => $event['properties']['$feature_flag_response'], $events)
        );
    }

    public function testFeatureFlagCalledFiresPerGroupContext(): void
    {
        $this->makeClient();

        // Same user, same flag, two different group contexts must produce two events.
        $snapA = PostHog::evaluateFlags('user-1', groups: ['organization' => 'org-a']);
        $snapA->isEnabled('simple-test');

        $snapB = PostHog::evaluateFlags('user-1', groups: ['organization' => 'org-b']);
        $snapB->isEnabled('simple-test');

        PostHog::flush();

        $events = [];
        foreach ($this->batchRequests() as $batch) {
            foreach ($batch['batch'] as $event) {
                if (
                    $event['event'] === '$feature_flag_called'
                    && ($event['properties']['$feature_flag'] ?? null) === 'simple-test'
                ) {
                    $events[] = $event;
                }
            }
        }

        $this->assertCount(2, $events, 'expected one $feature_flag_called event per group context');
        $seen = array_map(
            fn($e) => $e['properties']['$groups']['organization'] ?? null,
            $events
        );
        $this->assertContains('org-a', $seen);
        $this->assertContains('org-b', $seen);
    }

    /**
     * @return array<string, array{list<array<string,string>>, int}>
     */
    public static function dedupCallsProvider(): array
    {
        return [
            'repeated calls under same group' => [
                [
                    ['organization' => 'org-a'],
                    ['organization' => 'org-a'],
                    ['organization' => 'org-a'],
                ],
                1,
            ],
            'same groups different key insertion order' => [
                [
                    ['organization' => 'org-a', 'team' => 'red'],
                    ['team' => 'red', 'organization' => 'org-a'],
                ],
                1,
            ],
        ];
    }

    /**
     * @param list<array<string,string>> $groupsPerCall
     */
    #[DataProvider('dedupCallsProvider')]
    public function testFeatureFlagCalledDedupes(array $groupsPerCall, int $expectedCount): void
    {
        $this->makeClient();

        foreach ($groupsPerCall as $groups) {
            $snap = PostHog::evaluateFlags('user-1', groups: $groups);
            $snap->isEnabled('simple-test');
        }

        PostHog::flush();

        $count = 0;
        foreach ($this->batchRequests() as $batch) {
            foreach ($batch['batch'] as $event) {
                if (
                    $event['event'] === '$feature_flag_called'
                    && ($event['properties']['$feature_flag'] ?? null) === 'simple-test'
                ) {
                    $count++;
                }
            }
        }
        $this->assertSame($expectedCount, $count, 'unexpected $feature_flag_called event count');
    }

    /**
     * @return list<string> the deprecation messages emitted while running $callable
     */
    private function captureDeprecations(callable $callable): array
    {
        $messages = [];
        $previous = set_error_handler(
            function (int $errno, string $errstr) use (&$messages, &$previous) {
                if ($errno === E_USER_DEPRECATED) {
                    $messages[] = $errstr;
                    return true;
                }
                if ($previous !== null) {
                    return ($previous)($errno, $errstr);
                }
                return false;
            },
            E_USER_DEPRECATED
        );

        try {
            $callable();
        } finally {
            restore_error_handler();
        }

        return $messages;
    }

    public function testIsFeatureEnabledEmitsDeprecationWarning(): void
    {
        $this->makeClient();
        $messages = $this->captureDeprecations(
            fn() => $this->client->isFeatureEnabled('simple-test', 'user-1')
        );

        $this->assertCount(1, $messages, 'Expected exactly one deprecation warning');
        $this->assertStringContainsString('isFeatureEnabled', $messages[0]);
        $this->assertStringContainsString('evaluateFlags', $messages[0]);
    }

    public function testGetFeatureFlagEmitsDeprecationWarning(): void
    {
        $this->makeClient();
        $messages = $this->captureDeprecations(
            fn() => $this->client->getFeatureFlag('simple-test', 'user-1')
        );

        $this->assertCount(1, $messages);
        $this->assertStringContainsString('getFeatureFlag', $messages[0]);
        $this->assertStringContainsString('evaluateFlags', $messages[0]);
    }

    public function testGetFeatureFlagPayloadEmitsDeprecationWarning(): void
    {
        $this->makeClient();
        $messages = $this->captureDeprecations(
            fn() => $this->client->getFeatureFlagPayload('json-payload', 'user-1')
        );

        $this->assertCount(1, $messages);
        $this->assertStringContainsString('getFeatureFlagPayload', $messages[0]);
        $this->assertStringContainsString('evaluateFlags', $messages[0]);
    }

    public function testCaptureSendFeatureFlagsEmitsDeprecationWarning(): void
    {
        $this->makeClient();
        $messages = $this->captureDeprecations(
            fn() => $this->client->capture([
                'distinct_id' => 'user-1',
                'event' => 'page_view',
                'send_feature_flags' => true,
            ])
        );

        $this->assertCount(1, $messages);
        $this->assertStringContainsString('send_feature_flags', $messages[0]);
        $this->assertStringContainsString('evaluateFlags', $messages[0]);
    }

    public function testIsFeatureEnabledDoesNotCascadeDeprecationWarnings(): void
    {
        // isFeatureEnabled bypasses the public getFeatureFlag() so users see only one
        // warning per call, not two (or three if getFeatureFlagResult were also deprecated).
        $this->makeClient();
        $messages = $this->captureDeprecations(
            fn() => $this->client->isFeatureEnabled('simple-test', 'user-1')
        );

        $this->assertCount(1, $messages);
    }

    private static function localFlag(
        string $key,
        array $properties = [],
        bool $continuity = false,
        bool $active = true
    ): array {
        return [
            'id' => 1,
            'key' => $key,
            'active' => $active,
            'ensure_experience_continuity' => $continuity,
            'filters' => ['groups' => [['properties' => $properties, 'rollout_percentage' => 100]]],
        ];
    }

    private static function localDefinitions(array ...$flags): array
    {
        return ['flags' => $flags, 'group_type_mapping' => [], 'cohorts' => []];
    }

    private static function planFilter(string $operator = 'exact', string $value = 'pro'): array
    {
        return ['key' => 'plan', 'type' => 'person', 'operator' => $operator, 'value' => $value];
    }

    private static function staticCohortFilter(): array
    {
        return ['key' => 'id', 'type' => 'cohort', 'value' => 999];
    }

    private static function dependsOnFilter(string $key): array
    {
        return [
            'key' => $key,
            'type' => 'flag',
            'operator' => 'flag_evaluates_to',
            'value' => true,
            'dependency_chain' => [$key],
        ];
    }

    private function makeLocalClient(
        array $localEvaluationResponse,
        array $flagsEndpointResponse = ['flags' => []],
        int $flagsEndpointResponseCode = 200
    ): void {
        $this->makeClient(
            flagsEndpointResponse: $flagsEndpointResponse,
            localEvaluationResponse: $localEvaluationResponse,
            personalApiKey: 'test-personal-key',
            flagsEndpointResponseCode: $flagsEndpointResponseCode
        );
    }

    /** @return list<array<string, mixed>> Properties of each delivered $feature_flag_called event for $key. */
    private function flagCalledEvents(string $key): array
    {
        $this->client->flush();
        $events = [];
        foreach ($this->batchRequests() as $batch) {
            foreach ($batch['batch'] as $event) {
                if ($event['event'] === '$feature_flag_called' && $event['properties']['$feature_flag'] === $key) {
                    $events[] = $event['properties'];
                }
            }
        }
        return $events;
    }

    public function testLocalOnlyEvaluationReportsExperienceContinuityFlagAsUnresolved(): void
    {
        $this->makeLocalClient(self::localDefinitions(
            self::localFlag('beta-ui'),
            self::localFlag('checkout', continuity: true),
        ));

        $snapshot = $this->client->evaluateFlags('user-123', onlyEvaluateLocally: true);

        $this->assertSame(['beta-ui'], $snapshot->getKeys());
        $this->assertSame(['checkout' => UnresolvedFlagReason::EXPERIENCE_CONTINUITY], $snapshot->getUnresolvedFlags());
        $this->assertSame(0, $this->flagsRequestCount());
        $this->client->flush();
        $this->assertSame([], $this->batchRequests());
        $this->assertSame([], $snapshot->onlyAccessed()->getKeys());
        $this->assertSame([], $snapshot->onlyAccessed()->getUnresolvedFlags());
        $this->assertSame([], $snapshot->only(['beta-ui', 'checkout'])->getUnresolvedFlags());
        $this->assertSame(true, $snapshot->getFlag('beta-ui'));
    }

    #[DataProvider('unresolvedReasonProvider')]
    public function testEachInconclusiveCauseReportsItsUnresolvedReason(
        array $properties,
        array $personProperties,
        string $reason
    ): void {
        $this->makeLocalClient(self::localDefinitions(self::localFlag('checkout', $properties)));

        $snapshot = $this->client->evaluateFlags(
            'user-123',
            personProperties: $personProperties,
            onlyEvaluateLocally: true
        );

        $this->assertNotContains('checkout', $snapshot->getKeys());
        $this->assertSame(['checkout' => $reason], $snapshot->getUnresolvedFlags());
    }

    public static function unresolvedReasonProvider(): iterable
    {
        // The device-bucketed row of the spec is not applicable: this SDK has no device id bucketing.
        yield 'person property not passed' => [[self::planFilter()], [], UnresolvedFlagReason::MISSING_CONTEXT];
        yield 'unknown operator' => [
            [self::planFilter('not_a_real_operator')],
            ['plan' => 'enterprise'],
            UnresolvedFlagReason::UNSUPPORTED_DEFINITION,
        ];
        yield 'static cohort' => [[self::staticCohortFilter()], [], UnresolvedFlagReason::UNSUPPORTED_DEFINITION];
        yield 'static cohort and person property not passed' => [
            [self::staticCohortFilter(), self::planFilter()],
            [],
            UnresolvedFlagReason::UNSUPPORTED_DEFINITION,
        ];
        yield 'person property not passed and static cohort' => [
            [self::planFilter(), self::staticCohortFilter()],
            [],
            UnresolvedFlagReason::UNSUPPORTED_DEFINITION,
        ];
        yield 'malformed semver person property' => [
            [self::planFilter('semver_gt', '1.2.3')],
            ['plan' => 'not-a-version'],
            UnresolvedFlagReason::MISSING_CONTEXT,
        ];
        yield 'malformed semver filter' => [
            [self::planFilter('semver_gt', 'not-a-version')],
            ['plan' => '1.2.3'],
            UnresolvedFlagReason::UNSUPPORTED_DEFINITION,
        ];
    }

    public function testFlagBlockedByUnresolvedDependencyReportsTheDependency(): void
    {
        $this->makeLocalClient(self::localDefinitions(
            self::localFlag('checkout', continuity: true),
            self::localFlag('beta-ui', [self::dependsOnFilter('checkout')]),
        ));

        $snapshot = $this->client->evaluateFlags('user-123', onlyEvaluateLocally: true, flagKeys: ['beta-ui']);

        $this->assertSame([], $snapshot->getKeys());
        $this->assertSame(['beta-ui' => UnresolvedFlagReason::UNRESOLVED_DEPENDENCY], $snapshot->getUnresolvedFlags());
    }

    public function testFlagDependingOnFlagWithNoLoadedDefinitionReportsUnsupportedDefinition(): void
    {
        $this->makeLocalClient(self::localDefinitions(
            self::localFlag('beta-ui', [self::dependsOnFilter('checkout')]),
        ));

        $snapshot = $this->client->evaluateFlags('user-123', onlyEvaluateLocally: true, flagKeys: ['beta-ui']);

        $this->assertSame([], $snapshot->getKeys());
        $this->assertSame(['beta-ui' => UnresolvedFlagReason::UNSUPPORTED_DEFINITION], $snapshot->getUnresolvedFlags());
    }

    /** A flag whose first condition depends on `dep` and whose second condition matches everyone by default. */
    private static function flagWithDependencyAndCatchAll(array $laterConditionFilters = []): array
    {
        return [
            'id' => 2,
            'key' => 'target',
            'active' => true,
            'filters' => [
                'multivariate' => ['variants' => [
                    ['key' => 'a', 'rollout_percentage' => 50],
                    ['key' => 'b', 'rollout_percentage' => 50],
                ]],
                'groups' => [
                    ['properties' => [self::dependsOnFilter('dep')], 'rollout_percentage' => 100, 'variant' => 'a'],
                    ['properties' => $laterConditionFilters, 'rollout_percentage' => 100, 'variant' => 'b'],
                ],
            ],
        ];
    }

    #[DataProvider('dependencyThatNeedsServerProvider')]
    public function testFlagWithDependencyThatNeedsServerFallsBackToServer(?array $flagKeys): void
    {
        $this->makeLocalClient(
            self::localDefinitions(
                self::localFlag('dep', [self::staticCohortFilter()]),
                self::flagWithDependencyAndCatchAll(),
            ),
            ['flags' => ['target' => ['key' => 'target', 'enabled' => true, 'variant' => 'a']]]
        );

        $snapshot = $this->client->evaluateFlags('user-123', flagKeys: $flagKeys);

        $this->assertSame(1, $this->flagsRequestCount());
        $this->assertSame('a', $snapshot->getFlag('target'));
        $this->assertArrayNotHasKey('target', $snapshot->getUnresolvedFlags());
    }

    public static function dependencyThatNeedsServerProvider(): iterable
    {
        yield 'flag requested alone' => [['target']];
        yield 'all flags requested' => [null];
    }

    public function testFlagWithDependencyThatNeedsServerFallsBackToServerDespiteAMalformedLaterCondition(): void
    {
        global $errorMessages;
        $malformedDependency = array_merge(self::dependsOnFilter('dep'), ['dependency_chain' => [['dep']]]);
        $this->makeLocalClient(
            self::localDefinitions(
                self::localFlag('dep', [self::staticCohortFilter()]),
                self::flagWithDependencyAndCatchAll([$malformedDependency]),
                self::localFlag('everyone'),
            ),
            ['flags' => ['target' => ['key' => 'target', 'enabled' => true, 'variant' => 'a']]]
        );
        $loadMessages = $errorMessages;
        $errorMessages = [];

        $snapshot = $this->client->evaluateFlags('user-123', flagKeys: ['target', 'everyone']);

        $this->assertSame(
            [1, 'a', true, []],
            [
                $this->flagsRequestCount(),
                $snapshot->getFlag('target'),
                $snapshot->getFlag('everyone'),
                $errorMessages,
            ]
        );
        $this->assertCount(1, array_filter(
            $loadMessages,
            fn (string $message) => str_contains($message, 'could not check the definition') &&
                str_contains($message, 'target')
        ));
    }

    public function testFlagWithDependencyThatNeedsServerIsUnresolvedLocally(): void
    {
        $this->makeLocalClient(self::localDefinitions(
            self::localFlag('dep', [self::staticCohortFilter()]),
            self::flagWithDependencyAndCatchAll(),
        ));

        $snapshot = $this->client->evaluateFlags('user-123', onlyEvaluateLocally: true, flagKeys: ['target']);

        $this->assertSame([], $snapshot->getKeys());
        $this->assertSame(['target' => UnresolvedFlagReason::UNRESOLVED_DEPENDENCY], $snapshot->getUnresolvedFlags());
    }

    public function testFlagWithLocallyResolvedDependencyMatchesTheDependencyCondition(): void
    {
        $this->makeLocalClient(self::localDefinitions(
            self::localFlag('dep', [self::planFilter()]),
            self::flagWithDependencyAndCatchAll(),
        ));

        $snapshot = $this->client->evaluateFlags('user-123', personProperties: ['plan' => 'pro'], flagKeys: ['target']);

        $this->assertSame(0, $this->flagsRequestCount());
        $this->assertSame('a', $snapshot->getFlag('target'));
    }

    public function testFlagResolvedByRemoteFallbackIsNotUnresolved(): void
    {
        $this->makeLocalClient(
            self::localDefinitions(self::localFlag('checkout', continuity: true)),
            ['flags' => ['checkout' => ['key' => 'checkout', 'enabled' => true, 'variant' => null]]]
        );

        $snapshot = $this->client->evaluateFlags('user-123');

        $this->assertSame(['checkout'], $snapshot->getKeys());
        $this->assertSame([], $snapshot->getUnresolvedFlags());
        $this->assertTrue($snapshot->isEnabled('checkout'));

        $events = $this->flagCalledEvents('checkout');
        $this->assertCount(1, $events);
        $this->assertTrue($events[0]['$feature_flag_response']);
        $this->assertArrayNotHasKey('$feature_flag_error', $events[0]);
    }

    #[DataProvider('unresolvingFallbackProvider')]
    public function testRemoteFallbackThatDoesNotResolveTheFlagLeavesItUnresolved(
        array $flagsEndpointResponse,
        int $flagsEndpointResponseCode
    ): void {
        $this->makeLocalClient(
            self::localDefinitions(self::localFlag('checkout', continuity: true)),
            $flagsEndpointResponse,
            $flagsEndpointResponseCode
        );

        $snapshot = $this->client->evaluateFlags('user-123');

        $this->assertSame(1, $this->flagsRequestCount());
        $this->assertSame([], $snapshot->getKeys());
        $this->assertSame(['checkout' => UnresolvedFlagReason::EXPERIENCE_CONTINUITY], $snapshot->getUnresolvedFlags());
        $this->assertFalse($snapshot->isEnabled('checkout'));

        $events = $this->flagCalledEvents('checkout');
        $this->assertCount(1, $events);
        $this->assertSame('local_evaluation_inconclusive', $events[0]['$feature_flag_error']);
    }

    public static function unresolvingFallbackProvider(): iterable
    {
        yield 'request fails' => [['error' => 'unavailable'], 500];
        yield 'response has no flags' => [['flags' => []], 200];
        yield 'flag failed remotely' => [
            ['flags' => ['checkout' => ['key' => 'checkout', 'enabled' => false, 'failed' => true]]],
            200,
        ];
    }

    public function testKeyWithoutLocalDefinitionIsMissingNotUnresolved(): void
    {
        $this->makeLocalClient(self::localDefinitions(self::localFlag('beta-ui')));

        $snapshot = $this->client->evaluateFlags('user-123', flagKeys: ['beta-ui', 'typo-flag']);
        $snapshot->isEnabled('typo-flag');

        $this->assertSame([], $snapshot->getUnresolvedFlags());
        $events = $this->flagCalledEvents('typo-flag');
        $this->assertCount(1, $events);
        $this->assertSame('flag_missing', $events[0]['$feature_flag_error']);
    }

    public function testFlagOutsideRequestedKeysIsNotUnresolved(): void
    {
        $this->makeLocalClient(self::localDefinitions(
            self::localFlag('beta-ui'),
            self::localFlag('checkout', continuity: true),
        ));

        $snapshot = $this->client->evaluateFlags('user-123', flagKeys: ['beta-ui']);

        $this->assertSame(['beta-ui'], $snapshot->getKeys());
        $this->assertTrue($snapshot->isEnabled('beta-ui'));
        $this->assertSame([], $snapshot->getUnresolvedFlags());
        $this->assertSame(0, $this->flagsRequestCount());
    }

    public function testInactiveFlagIsResolvedAsFalseNotUnresolved(): void
    {
        $this->makeLocalClient(self::localDefinitions(
            self::localFlag('checkout', continuity: true, active: false),
        ));

        $snapshot = $this->client->evaluateFlags('user-123', onlyEvaluateLocally: true);

        $this->assertSame(['checkout'], $snapshot->getKeys());
        $this->assertFalse($snapshot->getFlag('checkout'));
        $this->assertSame([], $snapshot->getUnresolvedFlags());
    }

    public function testReadingUnresolvedFlagReportsInconclusiveLocalEvaluation(): void
    {
        $this->makeLocalClient(self::localDefinitions(self::localFlag('checkout', continuity: true)));

        $snapshot = $this->client->evaluateFlags('user-123', onlyEvaluateLocally: true);

        $this->assertFalse($snapshot->isEnabled('checkout'));
        $this->assertNull($snapshot->getFlag('checkout'));
        $this->assertSame(['checkout' => UnresolvedFlagReason::EXPERIENCE_CONTINUITY], $snapshot->getUnresolvedFlags());
        $events = $this->flagCalledEvents('checkout');
        $this->assertCount(1, $events);
        $this->assertNull($events[0]['$feature_flag_response']);
        $this->assertSame('local_evaluation_inconclusive', $events[0]['$feature_flag_error']);
    }

    public function testInconclusiveErrorCombinesWithResponseLevelErrors(): void
    {
        $this->makeLocalClient(
            self::localDefinitions(self::localFlag('checkout', continuity: true)),
            ['flags' => [], 'errorsWhileComputingFlags' => true]
        );

        $this->client->evaluateFlags('user-123')->isEnabled('checkout');

        $events = $this->flagCalledEvents('checkout');
        $this->assertCount(1, $events);
        $this->assertSame(
            'errors_while_computing_flags,local_evaluation_inconclusive',
            $events[0]['$feature_flag_error']
        );
    }

    public function testFilteredSnapshotReadOfUnresolvedFlagReportsFlagMissing(): void
    {
        $this->makeLocalClient(self::localDefinitions(self::localFlag('checkout', continuity: true)));

        $snapshot = $this->client->evaluateFlags('user-123', onlyEvaluateLocally: true);
        $snapshot->only(['checkout'])->isEnabled('checkout');

        $events = $this->flagCalledEvents('checkout');
        $this->assertCount(1, $events);
        $this->assertSame('flag_missing', $events[0]['$feature_flag_error']);
    }

    public function testUnexpectedLocalEvaluationErrorIsNotUnresolved(): void
    {
        $this->makeLocalClient(self::localDefinitions(self::localFlag('checkout', [self::planFilter()])));

        // A property value that cannot be encoded as JSON fails outside the inconclusive paths.
        $snapshot = $this->client->evaluateFlags(
            'user-123',
            personProperties: ['plan' => ["\xB1"]],
            onlyEvaluateLocally: true
        );
        $snapshot->isEnabled('checkout');

        $this->assertSame([], $snapshot->getKeys());
        $this->assertSame([], $snapshot->getUnresolvedFlags());
        $events = $this->flagCalledEvents('checkout');
        $this->assertCount(1, $events);
        $this->assertSame('flag_missing', $events[0]['$feature_flag_error']);
    }
}
