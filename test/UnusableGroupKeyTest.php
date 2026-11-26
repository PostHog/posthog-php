<?php

// phpcs:disable PSR1.Files.SideEffects
namespace PostHog\Test;

// comment out below to print all logs instead of failing tests
require_once 'test/error_log_mock.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use PostHog\Client;
use PostHog\FeatureFlag;
use PostHog\InconclusiveMatchException;
use PostHog\PostHog;
use PostHog\UnresolvedFlagReason;

/**
 * Like the flags service, a group key that isn't a non-empty string or a number counts as no group key
 * (PostHog/sdk-specs#250): its group conditions are skipped, so a group flag with only group conditions
 * resolves false locally.
 */
class UnusableGroupKeyTest extends TestCase
{
    private const REMOTE_VALUE = 'remote-value';

    private MockedHttpClient $httpClient;
    private Client $client;

    public function setUp(): void
    {
        date_default_timezone_set('UTC');
        global $errorMessages;
        $errorMessages = [];
    }

    public static function unusableGroupKeys(): array
    {
        return [
            'empty string' => [''],
            'null' => [null],
            'true' => [true],
            'false' => [false],
            'empty array' => [[]],
            'array' => [['org-1']],
            'object' => [new \stdClass()],
        ];
    }

    private function makeClient(array $flags): void
    {
        $remoteFlags = [];
        foreach ($flags as $flag) {
            $remoteFlags[$flag['key']] = [
                'key' => $flag['key'],
                'enabled' => true,
                'variant' => self::REMOTE_VALUE,
                'reason' => ['description' => 'Matched condition set 1'],
                'metadata' => ['id' => 1, 'version' => 1],
            ];
        }
        $this->httpClient = new MockedHttpClient(
            'app.posthog.com',
            flagEndpointResponse: [
                'flags' => $flags,
                'group_type_mapping' => ['0' => 'organization'],
                'cohorts' => (object) [],
            ],
            flagsEndpointResponse: ['flags' => $remoteFlags],
        );
        $this->client = new Client('random_key', [], $this->httpClient, 'test-personal-key');
        PostHog::init(null, null, $this->client);
    }

    private static function orgFlag(string $key = 'org-flag', array $properties = [], array $extra = []): array
    {
        return [
            'id' => 1,
            'key' => $key,
            'active' => true,
            'filters' => array_merge([
                'aggregation_group_type_index' => 0,
                'groups' => [['properties' => $properties, 'rollout_percentage' => 100]],
            ], $extra),
        ];
    }

    private static function mixedFlag(): array
    {
        return [
            'id' => 2,
            'key' => 'mixed-flag',
            'active' => true,
            'filters' => [
                'groups' => [
                    ['aggregation_group_type_index' => 0, 'properties' => [], 'rollout_percentage' => 100],
                    [
                        'properties' => [
                            ['key' => 'email', 'type' => 'person', 'operator' => 'exact', 'value' => 'a@example.com'],
                        ],
                        'rollout_percentage' => 100,
                    ],
                ],
            ],
        ];
    }

    private static function personChildOf(string $parentKey): array
    {
        return [
            'id' => 3,
            'key' => 'person-child',
            'active' => true,
            'filters' => [
                'groups' => [[
                    'properties' => [[
                        'key' => $parentKey,
                        'type' => 'flag',
                        'value' => true,
                        'operator' => 'flag_evaluates_to',
                        'dependency_chain' => [$parentKey],
                    ]],
                    'rollout_percentage' => 100,
                ]],
            ],
        ];
    }

    private static function computeLocally(array $flag, array $groups, array $flagsByKey = [], array $person = [])
    {
        return FeatureFlag::computeFlagLocally(
            $flag,
            'user-1',
            $groups,
            $person,
            [],
            [],
            $flagsByKey,
            ['0' => 'organization'],
            1,
            [],
            []
        );
    }

    private function remoteRequestCount(): int
    {
        return count(array_filter(
            $this->httpClient->calls ?? [],
            fn ($call) => str_starts_with($call['path'], '/flags/?')
        ));
    }

    #[DataProvider('unusableGroupKeys')]
    public function testGroupFlagWithUnusableGroupKeyResolvesFalseLocally(mixed $groupKey): void
    {
        $this->makeClient([self::orgFlag()]);
        $groups = ['organization' => $groupKey];

        $local = $this->client->evaluateFlags('user-1', groups: $groups, onlyEvaluateLocally: true);
        $this->assertFalse($local->getFlag('org-flag'));
        $this->assertSame([], $local->getUnresolvedFlags());

        $default = $this->client->evaluateFlags('user-1', groups: $groups);
        $this->assertFalse($default->getFlag('org-flag'));
        $this->assertSame([], $default->getUnresolvedFlags());
        $this->assertSame(0, $this->remoteRequestCount());
    }

    #[DataProvider('unusableGroupKeys')]
    #[IgnoreDeprecations]
    public function testDefaultGetterResolvesGroupFlagWithUnusableGroupKeyAsFalse(mixed $groupKey): void
    {
        $this->makeClient([self::orgFlag()]);
        $groups = ['organization' => $groupKey];

        $this->assertFalse($this->client->getFeatureFlag('org-flag', 'user-1', $groups, onlyEvaluateLocally: true));
        $this->assertFalse($this->client->getFeatureFlag('org-flag', 'user-1', $groups));
        $this->assertSame(0, $this->remoteRequestCount());
    }

    #[DataProvider('unusableGroupKeys')]
    public function testComputeFlagLocallyResolvesGroupFlagWithUnusableGroupKeyAsFalse(mixed $groupKey): void
    {
        $this->assertFalse(self::computeLocally(self::orgFlag(), ['organization' => $groupKey]));
    }

    #[DataProvider('unusableGroupKeys')]
    public function testUnusableGroupKeyIsNotUsedForHoldoutBucketing(mixed $groupKey): void
    {
        $flag = self::orgFlag(extra: ['holdout' => ['id' => 7, 'exclusion_percentage' => 100]]);

        try {
            self::computeLocally($flag, ['organization' => $groupKey]);
            $this->fail('Expected InconclusiveMatchException');
        } catch (InconclusiveMatchException $e) {
            $this->assertSame(UnresolvedFlagReason::MISSING_CONTEXT, $e->getReason());
        }
    }

    #[DataProvider('unusableGroupKeys')]
    public function testMixedConditionWithUnusableGroupKeyIsSkipped(mixed $groupKey): void
    {
        $this->makeClient([self::mixedFlag()]);
        $groups = ['organization' => $groupKey];

        $matching = $this->client->evaluateFlags(
            'user-1',
            groups: $groups,
            personProperties: ['email' => 'a@example.com']
        );
        $this->assertTrue($matching->getFlag('mixed-flag'));

        $notMatching = $this->client->evaluateFlags(
            'user-1',
            groups: $groups,
            personProperties: ['email' => 'b@example.com'],
            groupProperties: ['organization' => ['tier' => 'pro']]
        );
        $this->assertFalse($notMatching->getFlag('mixed-flag'));
        $this->assertSame([], $notMatching->getUnresolvedFlags());
        $this->assertSame(0, $this->remoteRequestCount());

        $this->assertFalse(self::computeLocally(self::mixedFlag(), $groups, [], ['email' => 'b@example.com']));
    }

    #[DataProvider('unusableGroupKeys')]
    public function testGroupDependencyWithUnusableGroupKeyResolvesFalse(mixed $groupKey): void
    {
        $parent = self::orgFlag('org-parent');
        $child = self::personChildOf('org-parent');
        $this->makeClient([$parent, $child]);
        $groups = ['organization' => $groupKey];

        $evaluations = $this->client->evaluateFlags('user-1', groups: $groups);
        $this->assertFalse($evaluations->getFlag('person-child'));
        $this->assertSame([], $evaluations->getUnresolvedFlags());
        $this->assertSame(0, $this->remoteRequestCount());

        $flagsByKey = ['org-parent' => $parent, 'person-child' => $child];
        $this->assertFalse(self::computeLocally($child, $groups, $flagsByKey));
    }

    #[DataProvider('unusableGroupKeys')]
    #[IgnoreDeprecations]
    public function testUnusableGroupKeyDoesNotBreakBulkEvaluationOrCapture(mixed $groupKey): void
    {
        $this->makeClient([self::orgFlag(), self::mixedFlag()]);
        $groups = ['organization' => $groupKey];

        $this->assertSame(
            ['org-flag' => false, 'mixed-flag' => false],
            $this->client->getAllFlags('user-1', $groups, ['email' => 'b@example.com'], onlyEvaluateLocally: true)
        );

        $this->assertTrue(PostHog::capture([
            'distinctId' => 'user-1',
            'event' => 'page_view',
            'groups' => $groups,
            'send_feature_flags' => true,
        ]));
        $this->assertSame(0, $this->remoteRequestCount());
    }

    public static function usableGroupKeys(): array
    {
        return [
            'string' => ['org-1', 'org-1'],
            'integer' => [42, '42'],
        ];
    }

    #[DataProvider('usableGroupKeys')]
    public function testUsableGroupKeyStillBucketsByItsCanonicalRendering(mixed $groupKey, string $rendering): void
    {
        $flag = self::orgFlag(extra: [
            'multivariate' => ['variants' => [
                ['key' => 'first', 'rollout_percentage' => 50],
                ['key' => 'second', 'rollout_percentage' => 50],
            ]],
        ]);

        $expected = FeatureFlag::matchFeatureFlagProperties($flag, $rendering, []);
        $this->assertContains($expected, ['first', 'second']);
        $this->assertSame($expected, self::computeLocally($flag, ['organization' => $groupKey]));
    }

    #[DataProvider('usableGroupKeys')]
    public function testUsableGroupKeyWithoutGroupPropertiesStaysInconclusive(mixed $groupKey): void
    {
        $tierFilter = [
            'key' => 'tier', 'type' => 'group', 'group_type_index' => 0, 'operator' => 'exact', 'value' => 'pro',
        ];
        $this->makeClient([self::orgFlag(properties: [$tierFilter])]);

        $local = $this->client->evaluateFlags(
            'user-1',
            groups: ['organization' => $groupKey],
            onlyEvaluateLocally: true
        );
        $this->assertSame(['org-flag' => UnresolvedFlagReason::MISSING_CONTEXT], $local->getUnresolvedFlags());

        try {
            self::computeLocally(self::orgFlag(properties: [$tierFilter]), ['organization' => $groupKey]);
            $this->fail('Expected InconclusiveMatchException');
        } catch (InconclusiveMatchException $e) {
            $this->assertSame(UnresolvedFlagReason::MISSING_CONTEXT, $e->getReason());
        }
    }
}
