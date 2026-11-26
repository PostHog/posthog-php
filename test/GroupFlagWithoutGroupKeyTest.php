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
 * Like the flags service, a condition aggregated by a group without its group key is skipped, one condition
 * at a time: a group flag with only such conditions resolves false locally, and its person conditions still run.
 */
class GroupFlagWithoutGroupKeyTest extends TestCase
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

    private static function orgFlag(array $properties = []): array
    {
        return [
            'id' => 1,
            'key' => 'org-flag',
            'active' => true,
            'filters' => [
                'aggregation_group_type_index' => 0,
                'groups' => [['properties' => $properties, 'rollout_percentage' => 100]],
            ],
        ];
    }

    private static function mixedFlag(): array
    {
        return [
            'id' => 1,
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

    private function remoteRequestCount(): int
    {
        return count(array_filter(
            $this->httpClient->calls ?? [],
            fn ($call) => str_starts_with($call['path'], '/flags/?')
        ));
    }

    public function testGroupFlagWithoutItsGroupKeyResolvesFalseLocally(): void
    {
        $this->makeClient([self::orgFlag()]);

        $local = $this->client->evaluateFlags('user-1', onlyEvaluateLocally: true);
        $this->assertFalse($local->getFlag('org-flag'));
        $this->assertSame([], $local->getUnresolvedFlags());

        $default = $this->client->evaluateFlags('user-1', groups: ['project' => 'project-1']);
        $this->assertFalse($default->getFlag('org-flag'));
        $this->assertSame(0, $this->remoteRequestCount());
    }

    #[IgnoreDeprecations]
    public function testDefaultGetterResolvesGroupFlagWithoutItsGroupKeyAsFalse(): void
    {
        $this->makeClient([self::orgFlag()]);

        $this->assertFalse($this->client->getFeatureFlag('org-flag', 'user-1'));
        $this->assertSame(0, $this->remoteRequestCount());
    }

    public function testComputeFlagLocallyResolvesGroupFlagWithoutItsGroupKeyAsFalse(): void
    {
        $this->assertFalse(FeatureFlag::computeFlagLocally(
            self::orgFlag(),
            'user-1',
            [],
            [],
            [],
            [],
            [],
            ['0' => 'organization'],
            1,
            [],
            []
        ));
    }

    public function testComputeFlagLocallyKeepsUnknownGroupTypeInconclusive(): void
    {
        try {
            FeatureFlag::computeFlagLocally(self::orgFlag(), 'user-1', [], [], [], [], [], [], 1, [], []);
            $this->fail('Expected InconclusiveMatchException');
        } catch (InconclusiveMatchException $e) {
            $this->assertSame(UnresolvedFlagReason::UNSUPPORTED_DEFINITION, $e->getReason());
        }
    }

    public function testGroupKeyWithoutGroupPropertiesStaysInconclusive(): void
    {
        $tierFilter = [
            'key' => 'tier', 'type' => 'group', 'group_type_index' => 0, 'operator' => 'exact', 'value' => 'pro',
        ];
        $this->makeClient([self::orgFlag([$tierFilter])]);
        $groups = ['organization' => 'org-1'];

        $local = $this->client->evaluateFlags('user-1', groups: $groups, onlyEvaluateLocally: true);
        $this->assertSame(['org-flag' => UnresolvedFlagReason::MISSING_CONTEXT], $local->getUnresolvedFlags());

        $default = $this->client->evaluateFlags('user-1', groups: $groups);
        $this->assertSame(self::REMOTE_VALUE, $default->getFlag('org-flag'));
        $this->assertSame(1, $this->remoteRequestCount());
    }

    public function testMixedConditionWithoutItsGroupKeyIsSkipped(): void
    {
        $this->makeClient([self::mixedFlag()]);

        $matching = $this->client->evaluateFlags('user-1', personProperties: ['email' => 'a@example.com']);
        $this->assertTrue($matching->getFlag('mixed-flag'));

        $notMatching = $this->client->evaluateFlags('user-1', personProperties: ['email' => 'b@example.com']);
        $this->assertFalse($notMatching->getFlag('mixed-flag'));
        $this->assertSame([], $notMatching->getUnresolvedFlags());

        $this->assertSame(0, $this->remoteRequestCount());
    }

    private const PRO_PLAN = ['key' => 'plan', 'type' => 'person', 'operator' => 'exact', 'value' => 'pro'];

    /**
     * A flag aggregated by organization with an explicit person condition
     * (`aggregation_group_type_index: null`), optionally after a condition that inherits the group.
     */
    private static function orgFlagWithPersonCondition(bool $withGroupConditionFirst = false): array
    {
        $conditions = [
            ['aggregation_group_type_index' => null, 'properties' => [self::PRO_PLAN], 'rollout_percentage' => 100],
        ];
        if ($withGroupConditionFirst) {
            array_unshift($conditions, ['properties' => [], 'rollout_percentage' => 100]);
        }
        return [
            'id' => 1,
            'key' => 'org-flag',
            'active' => true,
            'filters' => ['aggregation_group_type_index' => 0, 'groups' => $conditions],
        ];
    }

    private static function personChildOfOrgFlag(): array
    {
        return [
            'id' => 2,
            'key' => 'person-child',
            'active' => true,
            'filters' => [
                'groups' => [[
                    'properties' => [[
                        'key' => 'org-flag',
                        'type' => 'flag',
                        'value' => true,
                        'operator' => 'flag_evaluates_to',
                        'dependency_chain' => ['org-flag'],
                    ]],
                    'rollout_percentage' => 100,
                ]],
            ],
        ];
    }

    public static function missingOrganizationKeys(): array
    {
        return ['no organization group' => [[]], 'empty organization key' => [['organization' => '']]];
    }

    #[DataProvider('missingOrganizationKeys')]
    #[IgnoreDeprecations]
    public function testPersonConditionOfGroupFlagRunsWithoutTheGroupKey(array $groups): void
    {
        $this->makeClient([self::orgFlagWithPersonCondition()]);

        $pro = $this->client->evaluateFlags('user-1', groups: $groups, personProperties: ['plan' => 'pro']);
        $this->assertTrue($pro->getFlag('org-flag'));
        $free = $this->client->evaluateFlags('user-1', groups: $groups, personProperties: ['plan' => 'free']);
        $this->assertFalse($free->getFlag('org-flag'));
        $this->assertSame([], $free->getUnresolvedFlags());

        $this->assertTrue($this->client->getFeatureFlag('org-flag', 'user-1', $groups, ['plan' => 'pro']));
        $this->assertFalse($this->client->getFeatureFlag('org-flag', 'user-1', $groups, ['plan' => 'free']));
        $this->assertSame(0, $this->remoteRequestCount());
    }

    #[DataProvider('missingOrganizationKeys')]
    public function testPersonConditionOfGroupDependencyRunsWithoutTheGroupKey(array $groups): void
    {
        $this->makeClient([self::orgFlagWithPersonCondition(), self::personChildOfOrgFlag()]);

        $pro = $this->client->evaluateFlags('user-1', groups: $groups, personProperties: ['plan' => 'pro']);
        $this->assertTrue($pro->getFlag('person-child'));
        $free = $this->client->evaluateFlags('user-1', groups: $groups, personProperties: ['plan' => 'free']);
        $this->assertFalse($free->getFlag('person-child'));
        $this->assertSame([], $free->getUnresolvedFlags());
        $this->assertSame(0, $this->remoteRequestCount());
    }

    #[DataProvider('missingOrganizationKeys')]
    public function testPersonConditionDecidesAfterSkippedGroupCondition(array $groups): void
    {
        $this->makeClient([self::orgFlagWithPersonCondition(withGroupConditionFirst: true)]);

        $pro = $this->client->evaluateFlags('user-1', groups: $groups, personProperties: ['plan' => 'pro']);
        $this->assertTrue($pro->getFlag('org-flag'));
        $free = $this->client->evaluateFlags('user-1', groups: $groups, personProperties: ['plan' => 'free']);
        $this->assertFalse($free->getFlag('org-flag'));
        $this->assertSame([], $free->getUnresolvedFlags());
        $this->assertSame(0, $this->remoteRequestCount());

        $this->assertTrue(FeatureFlag::computeFlagLocally(
            self::orgFlagWithPersonCondition(withGroupConditionFirst: true),
            'user-1',
            $groups,
            ['plan' => 'pro'],
            [],
            [],
            [],
            ['0' => 'organization'],
            1,
            [],
            []
        ));
    }

    private static function orgFlagWithHoldout(float $exclusionPercentage): array
    {
        $flag = self::orgFlag();
        $flag['filters']['holdout'] = ['id' => 7, 'exclusion_percentage' => $exclusionPercentage];
        return $flag;
    }

    #[DataProvider('missingOrganizationKeys')]
    #[IgnoreDeprecations]
    public function testGroupHoldoutWithoutTheGroupKeyIsInconclusive(array $groups): void
    {
        $this->makeClient([self::orgFlagWithHoldout(50)]);

        $local = $this->client->evaluateFlags('user-1', groups: $groups, onlyEvaluateLocally: true);
        $this->assertSame(['org-flag' => UnresolvedFlagReason::MISSING_CONTEXT], $local->getUnresolvedFlags());
        $this->assertSame(0, $this->remoteRequestCount());

        $default = $this->client->evaluateFlags('user-1', groups: $groups);
        $this->assertSame(self::REMOTE_VALUE, $default->getFlag('org-flag'));
        $this->assertSame(1, $this->remoteRequestCount());

        $this->assertSame(self::REMOTE_VALUE, $this->client->getFeatureFlag('org-flag', 'user-1', $groups));
        $this->assertSame(2, $this->remoteRequestCount());
    }

    public function testGroupHoldoutWithTheGroupKeyIsUnchanged(): void
    {
        $this->makeClient([self::orgFlagWithHoldout(50)]);

        // "user-1" buckets into a 50% holdout, "org-1" does not.
        $inHoldout = $this->client->evaluateFlags('user-5', groups: ['organization' => 'user-1']);
        $this->assertSame('holdout-7', $inHoldout->getFlag('org-flag'));
        $outOfHoldout = $this->client->evaluateFlags('user-1', groups: ['organization' => 'user-5']);
        $this->assertTrue($outOfHoldout->getFlag('org-flag'));
        $this->assertSame(0, $this->remoteRequestCount());
    }

    #[DataProvider('missingOrganizationKeys')]
    public function testZeroPercentGroupHoldoutWithoutTheGroupKeyResolvesFalse(array $groups): void
    {
        $this->makeClient([self::orgFlagWithHoldout(0)]);

        $local = $this->client->evaluateFlags('user-1', groups: $groups, onlyEvaluateLocally: true);
        $this->assertFalse($local->getFlag('org-flag'));
        $this->assertSame([], $local->getUnresolvedFlags());

        $default = $this->client->evaluateFlags('user-1', groups: $groups);
        $this->assertFalse($default->getFlag('org-flag'));
        $this->assertSame(0, $this->remoteRequestCount());
    }
}
