<?php

// phpcs:disable PSR1.Files.SideEffects
namespace PostHog\Test;

// comment out below to print all logs instead of failing tests
require_once 'test/error_log_mock.php';

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use PostHog\Client;
use PostHog\FeatureFlag;
use PostHog\InconclusiveMatchException;
use PostHog\PostHog;
use PostHog\UnresolvedFlagReason;

class FeatureFlagKnownAbsentTest extends TestCase
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

    private function makeClient(array $flags, array $cohorts = [], array $groupTypeMapping = []): void
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
                'group_type_mapping' => (object) $groupTypeMapping,
                'cohorts' => (object) $cohorts,
            ],
            flagsEndpointResponse: ['flags' => $remoteFlags],
        );
        $this->client = new Client('random_key', [], $this->httpClient, 'test-personal-key');
        PostHog::init(null, null, $this->client);
    }

    private static function flag(string $key, array $properties, ?int $aggregation = null): array
    {
        $filters = ['groups' => [['properties' => $properties, 'rollout_percentage' => 100]]];
        if ($aggregation !== null) {
            $filters['aggregation_group_type_index'] = $aggregation;
        }
        return ['id' => 1, 'key' => $key, 'active' => true, 'filters' => $filters];
    }

    private static function personFilter(string $operator, mixed $value = 'pro', string $key = 'plan'): array
    {
        return ['key' => $key, 'type' => 'person', 'operator' => $operator, 'value' => $value];
    }

    /** @return list<array<string, mixed>> */
    private function flagsRequestBodies(): array
    {
        $bodies = [];
        foreach ($this->httpClient->calls ?? [] as $call) {
            if (str_starts_with($call['path'], '/flags/?')) {
                $bodies[] = json_decode($call['payload'], true);
            }
        }
        return $bodies;
    }

    #[DataProvider('knownAbsentOperatorProvider')]
    public function testKnownAbsentKeyResolvesOperatorLocally(string $operator, mixed $value, bool $expected): void
    {
        $this->makeClient([self::flag('flag', [self::personFilter($operator, $value)])]);

        $snapshot = $this->client->evaluateFlags('user-1', personPropertyKeysKnownAbsent: ['plan']);

        $this->assertSame($expected, $snapshot->getFlag('flag'));
        $this->assertSame([], $this->flagsRequestBodies());
    }

    public static function knownAbsentOperatorProvider(): iterable
    {
        yield 'is_not_set' => ['is_not_set', null, true];
        yield 'is_set' => ['is_set', null, false];
        yield 'exact' => ['exact', 'pro', false];
        yield 'is_not' => ['is_not', 'pro', true];
        yield 'icontains' => ['icontains', 'pro', false];
        yield 'not_icontains' => ['not_icontains', 'pro', true];
        yield 'starts_with' => ['starts_with', 'pro', false];
        yield 'not_starts_with' => ['not_starts_with', 'pro', true];
        yield 'ends_with' => ['ends_with', 'pro', false];
        yield 'not_ends_with' => ['not_ends_with', 'pro', true];
        yield 'regex' => ['regex', '^pro', false];
        yield 'not_regex' => ['not_regex', '^pro', true];
        yield 'gt' => ['gt', '10', false];
        yield 'gte' => ['gte', '10', false];
        yield 'lt' => ['lt', '10', false];
        yield 'lte' => ['lte', '10', false];
        yield 'semver_eq' => ['semver_eq', '1.2.3', false];
        yield 'semver_neq' => ['semver_neq', '1.2.3', false];
        yield 'semver_tilde' => ['semver_tilde', '1.2.3', false];
        yield 'semver_caret' => ['semver_caret', '1.2.3', false];
        yield 'semver_wildcard' => ['semver_wildcard', '1.2.*', false];
        yield 'is_date_before' => ['is_date_before', '2025-01-01T10:00:00Z', false];
        yield 'is_date_after' => ['is_date_after', '2025-01-01T10:00:00Z', false];
    }

    #[DataProvider('unsupportedFilterProvider')]
    public function testUnsupportedFilterOnKnownAbsentKeyStillFallsBack(string $operator, mixed $value): void
    {
        $this->makeClient([self::flag('flag', [self::personFilter($operator, $value)])]);

        $local = $this->client->evaluateFlags(
            'user-1',
            onlyEvaluateLocally: true,
            personPropertyKeysKnownAbsent: ['plan']
        );
        $this->assertSame(
            ['flag' => UnresolvedFlagReason::UNSUPPORTED_DEFINITION],
            $local->getUnresolvedFlags()
        );

        $snapshot = $this->client->evaluateFlags('user-1', personPropertyKeysKnownAbsent: ['plan']);
        $this->assertSame(self::REMOTE_VALUE, $snapshot->getFlag('flag'));
        $this->assertCount(1, $this->flagsRequestBodies());
    }

    public static function unsupportedFilterProvider(): iterable
    {
        yield 'not_icontains_multi' => ['not_icontains_multi', ['pro']];
        yield 'icontains_multi' => ['icontains_multi', ['pro']];
        yield 'not_between' => ['not_between', [1, 2]];
        yield 'between' => ['between', [1, 2]];
        yield 'unknown operator' => ['future_operator', 'pro'];
        yield 'malformed numeric filter' => ['gt', 'not-a-number'];
        yield 'malformed semver filter' => ['semver_neq', 'not-a-version'];
        yield 'malformed date filter' => ['is_date_before', 'not-a-date'];
        yield 'non-portable regex' => ['not_regex', '(?<=a+)b'];
    }

    public function testKnownAbsentKeyOverridesSuppliedValue(): void
    {
        $this->makeClient([self::flag('flag', [self::personFilter('is_not_set', null)])]);

        $snapshot = $this->client->evaluateFlags(
            'user-1',
            personProperties: ['plan' => 'pro'],
            personPropertyKeysKnownAbsent: ['plan']
        );

        $this->assertTrue($snapshot->getFlag('flag'));
        $this->assertSame([], $this->flagsRequestBodies());
    }

    public function testKnownAbsentKeyOverridesInjectedDistinctId(): void
    {
        $this->makeClient([self::flag('flag', [self::personFilter('is_not_set', null, 'distinct_id')])]);

        $snapshot = $this->client->evaluateFlags('user-1', personPropertyKeysKnownAbsent: ['distinct_id']);

        $this->assertTrue($snapshot->getFlag('flag'));
        $this->assertSame([], $this->flagsRequestBodies());
    }

    public function testUnlistedOmittedKeyStaysInconclusive(): void
    {
        $this->makeClient([self::flag('flag', [self::personFilter('is_not_set', null)])]);

        $local = $this->client->evaluateFlags(
            'user-1',
            onlyEvaluateLocally: true,
            personPropertyKeysKnownAbsent: ['region']
        );
        $this->assertSame(['flag' => UnresolvedFlagReason::MISSING_CONTEXT], $local->getUnresolvedFlags());

        $snapshot = $this->client->evaluateFlags('user-1', personPropertyKeysKnownAbsent: ['region']);
        $this->assertSame(self::REMOTE_VALUE, $snapshot->getFlag('flag'));
        $this->assertCount(1, $this->flagsRequestBodies());
    }

    public function testPersonMetadataKeyIsIgnored(): void
    {
        $metadataKey = '__posthog_person_metadata__created_at';
        $this->makeClient([self::flag('flag', [self::personFilter('is_set', null, $metadataKey)])]);

        $snapshot = $this->client->evaluateFlags(
            'user-1',
            personProperties: [$metadataKey => '2024-01-01T00:00:00Z'],
            personPropertyKeysKnownAbsent: [$metadataKey]
        );

        $this->assertTrue($snapshot->getFlag('flag'));
        $this->assertSame([], $this->flagsRequestBodies());
    }

    public function testGroupKnownAbsentKeyAppliesToItsGroupType(): void
    {
        $tierNotSet = [
            'key' => 'tier', 'type' => 'group', 'group_type_index' => 0, 'operator' => 'is_not_set', 'value' => null,
        ];
        $this->makeClient([self::flag('org-flag', [$tierNotSet], 0)], groupTypeMapping: ['0' => 'organization']);
        $groups = ['organization' => 'org-1', 'project' => 'project-1'];

        $listed = $this->client->evaluateFlags(
            'user-1',
            groups: $groups,
            groupProperties: ['organization' => ['tier' => 'gold']],
            groupPropertyKeysKnownAbsent: ['organization' => ['tier']]
        );
        $this->assertTrue($listed->getFlag('org-flag'));
        $this->assertSame([], $this->flagsRequestBodies());

        $otherType = $this->client->evaluateFlags(
            'user-1',
            groups: $groups,
            onlyEvaluateLocally: true,
            groupPropertyKeysKnownAbsent: ['project' => ['tier']]
        );
        $this->assertSame(['org-flag' => UnresolvedFlagReason::MISSING_CONTEXT], $otherType->getUnresolvedFlags());

        $personListed = $this->client->evaluateFlags(
            'user-1',
            groups: $groups,
            onlyEvaluateLocally: true,
            personPropertyKeysKnownAbsent: ['tier']
        );
        $this->assertSame(['org-flag' => UnresolvedFlagReason::MISSING_CONTEXT], $personListed->getUnresolvedFlags());
    }

    public function testGroupKnownAbsentKeyOverridesInjectedGroupKey(): void
    {
        $groupKeyNotSet = [
            'key' => '$group_key', 'type' => 'group', 'group_type_index' => 0,
            'operator' => 'is_not_set', 'value' => null,
        ];
        $this->makeClient([self::flag('org-flag', [$groupKeyNotSet], 0)], groupTypeMapping: ['0' => 'organization']);

        $snapshot = $this->client->evaluateFlags(
            'user-1',
            groups: ['organization' => 'org-1'],
            groupPropertyKeysKnownAbsent: ['organization' => ['$group_key']]
        );

        $this->assertTrue($snapshot->getFlag('org-flag'));
        $this->assertSame([], $this->flagsRequestBodies());
    }

    public function testMixedTargetingConditionUsesTheConditionGroupTypeKeys(): void
    {
        $flag = [
            'id' => 1,
            'key' => 'mixed-flag',
            'active' => true,
            'filters' => ['groups' => [[
                'aggregation_group_type_index' => 0,
                'properties' => [[
                    'key' => 'tier', 'type' => 'group', 'group_type_index' => 0,
                    'operator' => 'is_not_set', 'value' => null,
                ]],
                'rollout_percentage' => 100,
            ]]],
        ];
        $this->makeClient([$flag], groupTypeMapping: ['0' => 'organization']);

        $snapshot = $this->client->evaluateFlags(
            'user-1',
            groups: ['organization' => 'org-1'],
            groupProperties: ['organization' => ['tier' => 'gold']],
            groupPropertyKeysKnownAbsent: ['organization' => ['tier']]
        );
        $this->assertTrue($snapshot->getFlag('mixed-flag'));
        $this->assertSame([], $this->flagsRequestBodies());

        $personListed = $this->client->evaluateFlags(
            'user-1',
            groups: ['organization' => 'org-1'],
            personPropertyKeysKnownAbsent: ['tier']
        );
        $this->assertSame(self::REMOTE_VALUE, $personListed->getFlag('mixed-flag'));
        $this->assertCount(1, $this->flagsRequestBodies());
    }

    #[DataProvider('cohortNegationProvider')]
    public function testDynamicCohortLeafAppliesNegationAfterKnownAbsence(
        string $operator,
        bool $negation,
        bool $expected
    ): void {
        $leaf = self::personFilter($operator) + ['negation' => $negation];
        $this->makeClient(
            [self::flag('cohort-flag', [['key' => 'id', 'type' => 'cohort', 'value' => 1]])],
            cohorts: ['1' => ['type' => 'AND', 'values' => [$leaf]]]
        );

        $snapshot = $this->client->evaluateFlags('user-1', personPropertyKeysKnownAbsent: ['plan']);

        $this->assertSame($expected, $snapshot->getFlag('cohort-flag'));
        $this->assertSame([], $this->flagsRequestBodies());
    }

    public static function cohortNegationProvider(): iterable
    {
        yield 'exact' => ['exact', false, false];
        yield 'negated exact' => ['exact', true, true];
        yield 'is_not' => ['is_not', false, true];
        yield 'negated is_not' => ['is_not', true, false];
    }

    public function testFlagDependencyUsesKnownAbsentKeys(): void
    {
        $dependency = self::flag('profile-incomplete', [self::personFilter('is_not_set', null)]);
        $dependent = self::flag('dependent', [[
            'key' => 'profile-incomplete',
            'type' => 'flag',
            'value' => true,
            'operator' => 'flag_evaluates_to',
            'dependency_chain' => ['profile-incomplete'],
        ]]);
        $this->makeClient([$dependency, $dependent]);

        $snapshot = $this->client->evaluateFlags(
            'user-1',
            personProperties: ['plan' => 'pro'],
            personPropertyKeysKnownAbsent: ['plan']
        );

        $this->assertTrue($snapshot->getFlag('dependent'));
        $this->assertSame([], $this->flagsRequestBodies());
    }

    public function testRemoteFallbackSendsKnownAbsentKeys(): void
    {
        $this->makeClient([self::flag('flag', [self::personFilter('exact', 'pro', 'region')])]);

        $this->client->evaluateFlags(
            'user-1',
            personPropertyKeysKnownAbsent: ['plan'],
            groupPropertyKeysKnownAbsent: ['organization' => ['tier'], 'project' => []]
        );

        $bodies = $this->flagsRequestBodies();
        $this->assertCount(1, $bodies);
        $this->assertSame(['plan'], $bodies[0]['person_property_keys_known_absent']);
        $this->assertSame(['organization' => ['tier']], $bodies[0]['group_property_keys_known_absent']);
    }

    public function testRemoteRequestOmitsKnownAbsentFieldsWhenNotProvided(): void
    {
        $this->makeClient([self::flag('flag', [self::personFilter('exact', 'pro', 'region')])]);

        $this->client->evaluateFlags('user-1');
        $this->client->evaluateFlags(
            'user-1',
            personPropertyKeysKnownAbsent: [],
            groupPropertyKeysKnownAbsent: ['organization' => []]
        );

        $bodies = $this->flagsRequestBodies();
        $this->assertCount(2, $bodies);
        foreach ($bodies as $body) {
            $this->assertArrayNotHasKey('person_property_keys_known_absent', $body);
            $this->assertArrayNotHasKey('group_property_keys_known_absent', $body);
        }
    }

    #[IgnoreDeprecations]
    public function testLegacyFlagMethodsAcceptKnownAbsentKeys(): void
    {
        $this->makeClient([self::flag('flag', [self::personFilter('is_not_set', null)])]);
        $absent = ['plan'];

        $this->assertTrue(PostHog::isFeatureEnabled('flag', 'user-1', personPropertyKeysKnownAbsent: $absent));
        $this->assertTrue(PostHog::getFeatureFlag('flag', 'user-1', personPropertyKeysKnownAbsent: $absent));
        $this->assertTrue(
            PostHog::getFeatureFlagResult('flag', 'user-1', personPropertyKeysKnownAbsent: $absent)->getValue()
        );
        $this->assertNull(PostHog::getFeatureFlagPayload('flag', 'user-1', personPropertyKeysKnownAbsent: $absent));
        $this->assertSame(
            ['flag' => true],
            PostHog::getAllFlags('user-1', personPropertyKeysKnownAbsent: $absent)
        );
        $this->assertTrue(
            PostHog::evaluateFlags('user-1', personPropertyKeysKnownAbsent: $absent)->getFlag('flag')
        );
        $this->assertSame([], $this->flagsRequestBodies());
    }

    #[IgnoreDeprecations]
    public function testLegacyRemoteFallbackSendsKnownAbsentKeys(): void
    {
        $this->makeClient([self::flag('flag', [self::personFilter('exact', 'pro', 'region')])]);

        $this->client->getFeatureFlag('flag', 'user-1', personPropertyKeysKnownAbsent: ['plan']);
        $this->client->getAllFlags('user-1', personPropertyKeysKnownAbsent: ['plan']);

        $bodies = $this->flagsRequestBodies();
        $this->assertCount(2, $bodies);
        foreach ($bodies as $body) {
            $this->assertSame(['plan'], $body['person_property_keys_known_absent']);
        }
    }

    public function testThousandKnownAbsentKeysAreAccepted(): void
    {
        $this->makeClient([self::flag('flag', [self::personFilter('exact', 'pro', 'region')])]);
        $keys = array_fill(0, 1000, 'plan');

        $this->client->evaluateFlags(
            'user-1',
            personPropertyKeysKnownAbsent: $keys,
            groupPropertyKeysKnownAbsent: ['organization' => $keys, 'project' => $keys]
        );

        $bodies = $this->flagsRequestBodies();
        $this->assertCount(1, $bodies);
        $this->assertCount(1000, $bodies[0]['person_property_keys_known_absent']);
        $this->assertCount(1000, $bodies[0]['group_property_keys_known_absent']['project']);
    }

    #[DataProvider('invalidKnownAbsentKeysProvider')]
    public function testInvalidKnownAbsentKeysFailWithoutRequest(?array $personKeys, ?array $groupKeys): void
    {
        $this->makeClient([self::flag('flag', [self::personFilter('exact', 'pro', 'region')])]);

        try {
            $this->client->evaluateFlags(
                'user-1',
                personPropertyKeysKnownAbsent: $personKeys,
                groupPropertyKeysKnownAbsent: $groupKeys
            );
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('KnownAbsent', $e->getMessage());
        }

        $this->assertSame([], $this->flagsRequestBodies());
    }

    public static function invalidKnownAbsentKeysProvider(): iterable
    {
        yield 'person keys over the limit, counting duplicates' => [array_fill(0, 1001, 'plan'), null];
        yield 'group keys over the limit for one group type' => [null, ['organization' => array_fill(0, 1001, 'tier')]];
        yield 'non-string person key' => [[1], null];
        yield 'group keys not a list' => [null, ['organization' => 'tier']];
    }

    #[IgnoreDeprecations]
    public function testLegacyMethodsRejectKnownAbsentKeysOverTheLimit(): void
    {
        $this->makeClient([self::flag('flag', [self::personFilter('exact', 'pro', 'region')])]);
        $keys = array_fill(0, 1001, 'plan');

        foreach (
            [
                fn () => $this->client->getFeatureFlag('flag', 'user-1', personPropertyKeysKnownAbsent: $keys),
                fn () => $this->client->getAllFlags('user-1', personPropertyKeysKnownAbsent: $keys),
                fn () => $this->client->flags('user-1', personPropertyKeysKnownAbsent: $keys),
            ] as $call
        ) {
            try {
                $call();
                $this->fail('Expected InvalidArgumentException');
            } catch (InvalidArgumentException) {
            }
        }

        $this->assertSame([], $this->flagsRequestBodies());
    }

    public function testMatchPropertyTreatsListedKeyAsAbsent(): void
    {
        $this->assertTrue(FeatureFlag::matchProperty(
            self::personFilter('is_not_set', null),
            ['plan' => 'pro'],
            1,
            ['plan' => true]
        ));
        $this->assertFalse(FeatureFlag::matchProperty(
            self::personFilter('exact', 'pro'),
            ['plan' => 'pro'],
            1,
            ['plan' => true]
        ));

        $this->expectException(InconclusiveMatchException::class);
        FeatureFlag::matchProperty(self::personFilter('is_not_set', null), [], 1, ['region' => true]);
    }
}
