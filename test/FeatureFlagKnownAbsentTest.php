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
    }

    #[DataProvider('unreadConditionValueProvider')]
    public function testKnownAbsentKeyResolvesWithoutReadingConditionValue(
        string $operator,
        mixed $value,
        bool $expected
    ): void {
        $this->makeClient([self::flag('flag', [self::personFilter($operator, $value)])]);

        $local = $this->client->evaluateFlags(
            'user-1',
            onlyEvaluateLocally: true,
            personPropertyKeysKnownAbsent: ['plan']
        );
        $this->assertSame($expected, $local->getFlag('flag'));
        $this->assertSame([], $local->getUnresolvedFlags());

        $snapshot = $this->client->evaluateFlags('user-1', personPropertyKeysKnownAbsent: ['plan']);
        $this->assertSame($expected, $snapshot->getFlag('flag'));
        $this->assertSame([], $this->flagsRequestBodies());
    }

    public static function unreadConditionValueProvider(): iterable
    {
        yield 'not_regex with invalid pattern' => ['not_regex', '[', true];
        yield 'regex with invalid pattern' => ['regex', '[', false];
        yield 'not_regex with non-portable pattern' => ['not_regex', '(?<=a+)b', true];
        yield 'regex with non-portable pattern' => ['regex', '(?<=a+)b', false];
        yield 'not_regex with unicode class' => ['not_regex', '\p{L}', true];
        yield 'regex with unicode class' => ['regex', '\p{L}', false];
        yield 'gt with malformed number' => ['gt', 'not-a-number', false];
        yield 'gte with malformed number' => ['gte', 'not-a-number', false];
        yield 'lt with malformed number' => ['lt', 'not-a-number', false];
        yield 'lte with malformed number' => ['lte', 'not-a-number', false];
        yield 'semver_eq with malformed version' => ['semver_eq', 'not-a-version', false];
        yield 'semver_neq with malformed version' => ['semver_neq', 'not-a-version', false];
        yield 'semver_tilde with malformed version' => ['semver_tilde', 'not-a-version', false];
        yield 'semver_caret with malformed version' => ['semver_caret', 'not-a-version', false];
        yield 'semver_wildcard with malformed version' => ['semver_wildcard', 'not-a-version', false];
        yield 'is_date_before with malformed date' => ['is_date_before', 'not-a-date', false];
        yield 'is_date_after with malformed date' => ['is_date_after', 'not-a-date', false];
    }

    #[DataProvider('omittedConditionValueProvider')]
    public function testOmittedConditionValueOnKnownAbsentKeyStaysInconclusive(array $filter): void
    {
        $this->makeClient([self::flag('flag', [$filter])]);

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

    public static function omittedConditionValueProvider(): iterable
    {
        yield 'is_not with value omitted' => [['key' => 'plan', 'type' => 'person', 'operator' => 'is_not']];
        yield 'is_not with null value' => [self::personFilter('is_not', null)];
        yield 'exact with null value' => [self::personFilter('exact', null)];
        yield 'gt with null value' => [self::personFilter('gt', null)];
        yield 'not_regex with value omitted' => [['key' => 'plan', 'type' => 'person', 'operator' => 'not_regex']];
        yield 'not_regex with null value' => [self::personFilter('not_regex', null)];
    }

    #[DataProvider('omittedOrNullValueOnListedKeyProvider')]
    public function testOmittedOrNullConditionValueOnListedKeyStaysInconclusive(
        array $filter,
        array $personProperties
    ): void {
        $this->makeClient([self::flag('flag', [$filter])]);

        $local = $this->client->evaluateFlags(
            'user-1',
            personProperties: $personProperties,
            onlyEvaluateLocally: true,
            personPropertyKeysKnownAbsent: ['plan']
        );
        $this->assertSame(
            ['flag' => UnresolvedFlagReason::UNSUPPORTED_DEFINITION],
            $local->getUnresolvedFlags()
        );

        $snapshot = $this->client->evaluateFlags(
            'user-1',
            personProperties: $personProperties,
            personPropertyKeysKnownAbsent: ['plan']
        );
        $this->assertSame(self::REMOTE_VALUE, $snapshot->getFlag('flag'));
        $this->assertCount(1, $this->flagsRequestBodies());

        try {
            FeatureFlag::matchProperty($filter, $personProperties, 1, ['plan' => true]);
            $this->fail('Expected an inconclusive match');
        } catch (InconclusiveMatchException $e) {
            $this->assertSame(UnresolvedFlagReason::UNSUPPORTED_DEFINITION, $e->getReason());
        }
    }

    public static function omittedOrNullValueOnListedKeyProvider(): iterable
    {
        foreach (['exact', 'is_not', 'icontains', 'gt', 'is_date_before'] as $operator) {
            $omitted = ['key' => 'plan', 'type' => 'person', 'operator' => $operator];
            foreach (['listed only' => [], 'listed and supplied' => ['plan' => 'pro']] as $case => $properties) {
                yield "{$operator} with value omitted, {$case}" => [$omitted, $properties];
                yield "{$operator} with null value, {$case}" => [$omitted + ['value' => null], $properties];
            }
        }
    }

    #[DataProvider('setOperatorOmittedOrNullValueProvider')]
    public function testSetOperatorWithOmittedOrNullValueOnListedKeyResolvesLocally(
        array $filter,
        array $personProperties,
        bool $expected
    ): void {
        $this->makeClient([self::flag('flag', [$filter])]);

        $snapshot = $this->client->evaluateFlags(
            'user-1',
            personProperties: $personProperties,
            personPropertyKeysKnownAbsent: ['plan']
        );

        $this->assertSame($expected, $snapshot->getFlag('flag'));
        $this->assertSame([], $this->flagsRequestBodies());
    }

    public static function setOperatorOmittedOrNullValueProvider(): iterable
    {
        foreach (['is_not_set' => true, 'is_set' => false] as $operator => $expected) {
            $omitted = ['key' => 'plan', 'type' => 'person', 'operator' => $operator];
            foreach (['listed only' => [], 'listed and supplied' => ['plan' => 'pro']] as $case => $properties) {
                yield "{$operator} with value omitted, {$case}" => [$omitted, $properties, $expected];
                yield "{$operator} with null value, {$case}" => [$omitted + ['value' => null], $properties, $expected];
            }
        }
    }

    #[DataProvider('listedAndSuppliedValuePresentProvider')]
    public function testListedAndSuppliedKeyWithConditionValueResolvesFromListing(
        string $operator,
        mixed $value,
        bool $expected
    ): void {
        $this->makeClient([self::flag('flag', [self::personFilter($operator, $value)])]);

        $snapshot = $this->client->evaluateFlags(
            'user-1',
            personProperties: ['plan' => 'pro'],
            personPropertyKeysKnownAbsent: ['plan']
        );

        $this->assertSame($expected, $snapshot->getFlag('flag'));
        $this->assertSame([], $this->flagsRequestBodies());
    }

    public static function listedAndSuppliedValuePresentProvider(): iterable
    {
        yield 'exact' => ['exact', 'pro', false];
        yield 'is_not' => ['is_not', 'pro', true];
        yield 'icontains' => ['icontains', 'pro', false];
        yield 'gt' => ['gt', '10', false];
        yield 'is_date_before' => ['is_date_before', '2025-01-01T10:00:00Z', false];
    }

    #[DataProvider('unlistedSuppliedNullValueProvider')]
    public function testUnlistedSuppliedKeyWithNullConditionValueKeepsMatching(
        string $operator,
        bool|string $expected
    ): void {
        $this->makeClient([self::flag('flag', [self::personFilter($operator, null)])]);

        $local = $this->client->evaluateFlags(
            'user-1',
            personProperties: ['plan' => 'pro'],
            onlyEvaluateLocally: true,
            personPropertyKeysKnownAbsent: ['region']
        );

        if (is_bool($expected)) {
            $this->assertSame($expected, $local->getFlag('flag'));
            $this->assertSame([], $local->getUnresolvedFlags());
        } else {
            $this->assertSame(['flag' => $expected], $local->getUnresolvedFlags());
        }
    }

    public static function unlistedSuppliedNullValueProvider(): iterable
    {
        yield 'exact' => ['exact', false];
        yield 'is_not' => ['is_not', true];
        yield 'icontains' => ['icontains', false];
        yield 'gt' => ['gt', UnresolvedFlagReason::UNSUPPORTED_DEFINITION];
        yield 'is_date_before' => ['is_date_before', UnresolvedFlagReason::UNSUPPORTED_DEFINITION];
    }

    #[DataProvider('nullValueSetOperatorProvider')]
    public function testSetOperatorWithNullValueOnKnownAbsentKeyResolvesLocally(
        string $operator,
        bool $expected
    ): void {
        $this->makeClient([self::flag('flag', [self::personFilter($operator, null)])]);

        $snapshot = $this->client->evaluateFlags('user-1', personPropertyKeysKnownAbsent: ['plan']);

        $this->assertSame($expected, $snapshot->getFlag('flag'));
        $this->assertSame([], $this->flagsRequestBodies());
    }

    public static function nullValueSetOperatorProvider(): iterable
    {
        yield 'is_not_set' => ['is_not_set', true];
        yield 'is_set' => ['is_set', false];
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

    #[DataProvider('cohortUnsupportedLeafProvider')]
    public function testDynamicCohortUnsupportedLeafOnKnownAbsentKeyFallsBack(bool $negation): void
    {
        $leaf = self::personFilter('not_between', [1, 2]) + ['negation' => $negation];
        $this->makeClient(
            [self::flag('cohort-flag', [['key' => 'id', 'type' => 'cohort', 'value' => 1]])],
            cohorts: ['1' => ['type' => 'AND', 'values' => [$leaf]]]
        );

        $local = $this->client->evaluateFlags(
            'user-1',
            onlyEvaluateLocally: true,
            personPropertyKeysKnownAbsent: ['plan']
        );
        $this->assertSame(
            ['cohort-flag' => UnresolvedFlagReason::UNSUPPORTED_DEFINITION],
            $local->getUnresolvedFlags()
        );

        $snapshot = $this->client->evaluateFlags('user-1', personPropertyKeysKnownAbsent: ['plan']);
        $this->assertSame(self::REMOTE_VALUE, $snapshot->getFlag('cohort-flag'));
        $this->assertCount(1, $this->flagsRequestBodies());
    }

    public static function cohortUnsupportedLeafProvider(): iterable
    {
        yield 'not_between' => [false];
        yield 'negated not_between' => [true];
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

    #[DataProvider('personMetadataFilterProvider')]
    public function testPersonMetadataFilterIgnoresListedKey(array $filter): void
    {
        $this->makeClient([self::flag('meta-flag', [$filter])]);

        $local = $this->client->evaluateFlags(
            'user-1',
            onlyEvaluateLocally: true,
            personPropertyKeysKnownAbsent: ['created_at']
        );
        $this->assertSame(['meta-flag' => UnresolvedFlagReason::MISSING_CONTEXT], $local->getUnresolvedFlags());

        $snapshot = $this->client->evaluateFlags('user-1', personPropertyKeysKnownAbsent: ['created_at']);
        $this->assertSame(self::REMOTE_VALUE, $snapshot->getFlag('meta-flag'));
        $this->assertCount(1, $this->flagsRequestBodies());
    }

    #[DataProvider('personMetadataFilterProvider')]
    public function testPersonMetadataCohortLeafIgnoresListedKey(array $filter): void
    {
        $this->makeClient(
            [self::flag('cohort-flag', [['key' => 'id', 'type' => 'cohort', 'value' => 1]])],
            cohorts: ['1' => ['type' => 'AND', 'values' => [$filter]]]
        );

        $snapshot = $this->client->evaluateFlags('user-1', personPropertyKeysKnownAbsent: ['created_at']);

        $this->assertSame(self::REMOTE_VALUE, $snapshot->getFlag('cohort-flag'));
        $this->assertCount(1, $this->flagsRequestBodies());
    }

    public static function personMetadataFilterProvider(): iterable
    {
        $createdAt = ['key' => 'created_at', 'type' => 'person_metadata'];
        yield 'is_not_set' => [$createdAt + ['operator' => 'is_not_set', 'value' => null]];
        yield 'is_date_after' => [$createdAt + ['operator' => 'is_date_after', 'value' => '2024-01-01T00:00:00Z']];
        yield 'is_date_before' => [$createdAt + ['operator' => 'is_date_before', 'value' => '2024-01-01T00:00:00Z']];
    }

    public function testPersonMetadataFilterOnListedKeyUsesSuppliedValue(): void
    {
        $filter = [
            'key' => 'created_at', 'type' => 'person_metadata',
            'operator' => 'is_date_after', 'value' => '2024-01-01T00:00:00Z',
        ];
        $this->makeClient([self::flag('meta-flag', [$filter])]);

        $snapshot = $this->client->evaluateFlags(
            'user-1',
            personProperties: ['created_at' => '2025-06-01T00:00:00Z'],
            personPropertyKeysKnownAbsent: ['created_at']
        );

        $this->assertTrue($snapshot->getFlag('meta-flag'));
        $this->assertSame([], $this->flagsRequestBodies());
    }

    public function testPersonKnownAbsentKeyIgnoresGroupFilterInPersonFlag(): void
    {
        $groupFilter = [
            'key' => 'tier', 'type' => 'group', 'group_type_index' => 0, 'operator' => 'is_not_set', 'value' => null,
        ];
        $this->makeClient([self::flag('group-in-person', [$groupFilter])], groupTypeMapping: ['0' => 'organization']);

        $snapshot = $this->client->evaluateFlags(
            'user-1',
            groups: ['organization' => 'org-1'],
            personPropertyKeysKnownAbsent: ['tier']
        );

        $this->assertSame(self::REMOTE_VALUE, $snapshot->getFlag('group-in-person'));
        $this->assertCount(1, $this->flagsRequestBodies());
    }

    public function testGroupKnownAbsentKeyIgnoresPersonFilterAndCohortLeafInGroupFlag(): void
    {
        $personFilter = self::personFilter('is_not_set', null, 'tier');
        $this->makeClient(
            [
                self::flag('person-in-group', [$personFilter], 0),
                self::flag('cohort-in-group', [['key' => 'id', 'type' => 'cohort', 'value' => 1]], 0),
            ],
            cohorts: ['1' => ['type' => 'AND', 'values' => [$personFilter]]],
            groupTypeMapping: ['0' => 'organization']
        );

        $local = $this->client->evaluateFlags(
            'user-1',
            groups: ['organization' => 'org-1'],
            groupProperties: ['organization' => []],
            onlyEvaluateLocally: true,
            groupPropertyKeysKnownAbsent: ['organization' => ['tier']]
        );

        $this->assertSame(
            [
                'person-in-group' => UnresolvedFlagReason::MISSING_CONTEXT,
                'cohort-in-group' => UnresolvedFlagReason::MISSING_CONTEXT,
            ],
            $local->getUnresolvedFlags()
        );
    }

    public function testGroupKnownAbsentKeyIgnoresFilterOfAnotherGroupType(): void
    {
        $projectFilter = [
            'key' => 'tier', 'type' => 'group', 'group_type_index' => 1, 'operator' => 'is_not_set', 'value' => null,
        ];
        $this->makeClient(
            [self::flag('project-in-org', [$projectFilter], 0)],
            groupTypeMapping: ['0' => 'organization', '1' => 'project']
        );

        $local = $this->client->evaluateFlags(
            'user-1',
            groups: ['organization' => 'org-1', 'project' => 'project-1'],
            groupProperties: ['organization' => []],
            onlyEvaluateLocally: true,
            groupPropertyKeysKnownAbsent: ['organization' => ['tier']]
        );

        $this->assertSame(['project-in-org' => UnresolvedFlagReason::MISSING_CONTEXT], $local->getUnresolvedFlags());
    }

    public static function groupFilterWithoutIndexProvider(): iterable
    {
        yield 'exact' => ['exact', 'enterprise', false];
        yield 'is_not_set' => ['is_not_set', null, true];
    }

    #[DataProvider('groupFilterWithoutIndexProvider')]
    public function testGroupFilterWithoutIndexUsesTheConditionGroupTypeKeys(
        string $operator,
        ?string $value,
        bool $expected
    ): void {
        $tierFilter = ['key' => 'tier', 'type' => 'group', 'operator' => $operator, 'value' => $value];
        $this->makeClient([self::flag('legacy-tier', [$tierFilter], 0)], groupTypeMapping: ['0' => 'organization']);

        $local = $this->client->evaluateFlags(
            'user-1',
            groups: ['organization' => 'acme'],
            groupProperties: ['organization' => ['tier' => 'enterprise']],
            onlyEvaluateLocally: true,
            groupPropertyKeysKnownAbsent: ['organization' => ['tier']]
        );

        $this->assertSame($expected, $local->getFlag('legacy-tier'));
        $this->assertSame([], $local->getUnresolvedFlags());
        $this->assertSame([], $this->flagsRequestBodies());
    }

    public function testFlagDependencyWithOtherAggregationIgnoresParentKeys(): void
    {
        $groupDependency = self::flag('org-untiered', [[
            'key' => 'tier', 'type' => 'group', 'group_type_index' => 0, 'operator' => 'is_not_set', 'value' => null,
        ]], 0);
        $personDependency = self::flag('person-untiered', [self::personFilter('is_not_set', null, 'tier')]);
        $dependsOn = static fn (string $key): array => [[
            'key' => $key,
            'type' => 'flag',
            'value' => true,
            'operator' => 'flag_evaluates_to',
            'dependency_chain' => [$key],
        ]];
        $this->makeClient(
            [
                $groupDependency,
                $personDependency,
                self::flag('person-parent', $dependsOn('org-untiered')),
                self::flag('group-parent', $dependsOn('person-untiered'), 0),
            ],
            groupTypeMapping: ['0' => 'organization']
        );

        $personListed = $this->client->evaluateFlags(
            'user-1',
            groups: ['organization' => 'org-1'],
            groupProperties: ['organization' => []],
            onlyEvaluateLocally: true,
            flagKeys: ['person-parent'],
            personPropertyKeysKnownAbsent: ['tier']
        );
        $this->assertSame(
            ['person-parent' => UnresolvedFlagReason::UNRESOLVED_DEPENDENCY],
            $personListed->getUnresolvedFlags()
        );

        $groupListed = $this->client->evaluateFlags(
            'user-1',
            groups: ['organization' => 'org-1'],
            groupProperties: ['organization' => []],
            onlyEvaluateLocally: true,
            flagKeys: ['group-parent'],
            groupPropertyKeysKnownAbsent: ['organization' => ['tier']]
        );
        $this->assertSame(
            ['group-parent' => UnresolvedFlagReason::UNRESOLVED_DEPENDENCY],
            $groupListed->getUnresolvedFlags()
        );
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

    public function testMatchPropertyIgnoresListedKeyOnPersonMetadataFilter(): void
    {
        $this->assertTrue(FeatureFlag::matchProperty(
            ['key' => 'created_at', 'type' => 'person_metadata', 'operator' => 'is_set', 'value' => null],
            ['created_at' => '2024-01-01T00:00:00Z'],
            1,
            ['created_at' => true]
        ));
    }

    public function testDirectCallsIgnoreListedPersonMetadataPrefixedKey(): void
    {
        $metadataKey = '__posthog_person_metadata__created_at';
        $filter = self::personFilter('is_set', null, $metadataKey);
        $properties = [$metadataKey => '2024-01-01T00:00:00Z'];
        $listing = [$metadataKey => true];
        $flag = self::flag('flag', [$filter]);

        $this->assertTrue(FeatureFlag::matchProperty($filter, $properties, 1, $listing));
        $this->assertTrue(FeatureFlag::matchPropertyGroup(
            ['type' => 'AND', 'values' => [$filter]],
            $properties,
            [],
            knownAbsentKeys: $listing
        ));
        $this->assertTrue(FeatureFlag::matchFeatureFlagProperties(
            $flag,
            'user-1',
            $properties,
            knownAbsentKeys: $listing
        ));
        $this->assertTrue(FeatureFlag::computeFlagLocally(
            $flag,
            'user-1',
            [],
            $properties,
            [],
            [],
            ['flag' => $flag],
            [],
            1,
            $listing,
            []
        ));
    }

    public function testDirectMatchPropertyAppliesPrefixedKeyToGroupFilter(): void
    {
        $metadataKey = '__posthog_person_metadata__created_at';

        $this->assertFalse(FeatureFlag::matchProperty(
            ['key' => $metadataKey, 'type' => 'group', 'group_type_index' => 0, 'operator' => 'is_set', 'value' => null],
            [$metadataKey => '2024-01-01T00:00:00Z'],
            1,
            [$metadataKey => true]
        ));
    }

    public function testDirectMatchPropertyGroupAppliesKeysOnlyToPersonFilters(): void
    {
        $propertyGroup = ['type' => 'AND', 'values' => [[
            'key' => 'tier', 'type' => 'group', 'group_type_index' => 0, 'operator' => 'is_not_set', 'value' => null,
        ]]];

        $this->assertTrue(FeatureFlag::matchPropertyGroup(
            ['type' => 'AND', 'values' => [self::personFilter('is_not_set', null, 'tier')]],
            ['tier' => 'gold'],
            [],
            knownAbsentKeys: ['tier' => true]
        ));

        $this->expectException(InconclusiveMatchException::class);
        FeatureFlag::matchPropertyGroup($propertyGroup, [], [], knownAbsentKeys: ['tier' => true]);
    }

    public static function listedGroupKeyWithoutPropertyMapProvider(): iterable
    {
        yield 'is_not_set' => ['is_not_set', null, true];
        yield 'exact' => ['exact', 'pro', false];
    }

    private static function orgPlanFilter(string $operator, ?string $value): array
    {
        return [
            'key' => 'plan', 'type' => 'group', 'group_type_index' => 0, 'operator' => $operator, 'value' => $value,
        ];
    }

    private static function mixedOrgFlag(string $operator, ?string $value): array
    {
        return [
            'id' => 1,
            'key' => 'mixed-flag',
            'active' => true,
            'filters' => ['groups' => [[
                'aggregation_group_type_index' => 0,
                'properties' => [self::orgPlanFilter($operator, $value)],
                'rollout_percentage' => 100,
            ]]],
        ];
    }

    #[DataProvider('listedGroupKeyWithoutPropertyMapProvider')]
    public function testListedGroupKeyAppliesWithoutGroupPropertyMap(
        string $operator,
        ?string $value,
        bool $expected
    ): void {
        $groupFlag = self::flag('org-flag', [self::orgPlanFilter($operator, $value)], 0);
        $dependent = self::flag('dependent', [[
            'key' => 'org-flag',
            'type' => 'flag',
            'value' => true,
            'operator' => 'flag_evaluates_to',
            'dependency_chain' => ['org-flag'],
        ]]);
        $this->makeClient(
            [$groupFlag, self::mixedOrgFlag($operator, $value), $dependent],
            groupTypeMapping: ['0' => 'organization']
        );

        $snapshot = $this->client->evaluateFlags(
            'user-1',
            groups: ['organization' => 'org-1'],
            groupPropertyKeysKnownAbsent: ['organization' => ['plan']]
        );

        $this->assertSame($expected, $snapshot->getFlag('org-flag'));
        $this->assertSame($expected, $snapshot->getFlag('mixed-flag'));
        $this->assertSame($expected, $snapshot->getFlag('dependent'));
        $this->assertSame([], $this->flagsRequestBodies());
    }

    #[DataProvider('listedGroupKeyWithoutPropertyMapProvider')]
    public function testDirectMatchAppliesListedGroupKeyWithoutGroupPropertyMap(
        string $operator,
        ?string $value,
        bool $expected
    ): void {
        $groupFlag = self::flag('org-flag', [self::orgPlanFilter($operator, $value)], 0);
        $mixedFlag = self::mixedOrgFlag($operator, $value);
        $dependsOnOrgFlag = [
            'key' => 'org-flag',
            'type' => 'flag',
            'value' => true,
            'operator' => 'flag_evaluates_to',
            'dependency_chain' => ['org-flag'],
        ];
        $groupDependent = self::flag('org-dependent', [$dependsOnOrgFlag], 0);
        $flagsByKey = ['org-flag' => $groupFlag, 'mixed-flag' => $mixedFlag, 'org-dependent' => $groupDependent];
        $groups = ['organization' => 'org-1'];
        $mapping = ['0' => 'organization'];
        $listing = ['organization' => ['plan' => true]];

        $this->assertSame($expected, FeatureFlag::matchFeatureFlagProperties(
            $mixedFlag,
            'user-1',
            [],
            [],
            $flagsByKey,
            [],
            $groups,
            [],
            $mapping,
            1,
            [],
            $listing
        ));
        $this->assertSame($expected, FeatureFlag::matchFeatureFlagProperties(
            $groupDependent,
            'org-1',
            [],
            [],
            $flagsByKey,
            [],
            $groups,
            [],
            $mapping,
            1,
            ['plan' => true],
            $listing
        ));
    }

    public function testUnlistedGroupKeyWithoutGroupPropertyMapStaysInconclusive(): void
    {
        $groupFlag = self::flag('org-flag', [self::orgPlanFilter('is_not_set', null)], 0);
        $this->makeClient(
            [$groupFlag, self::mixedOrgFlag('is_not_set', null)],
            groupTypeMapping: ['0' => 'organization']
        );

        $local = $this->client->evaluateFlags(
            'user-1',
            groups: ['organization' => 'org-1'],
            onlyEvaluateLocally: true,
            groupPropertyKeysKnownAbsent: ['organization' => ['region']]
        );
        $this->assertSame(
            [
                'org-flag' => UnresolvedFlagReason::MISSING_CONTEXT,
                'mixed-flag' => UnresolvedFlagReason::MISSING_CONTEXT,
            ],
            $local->getUnresolvedFlags()
        );

        $snapshot = $this->client->evaluateFlags('user-1', groups: ['organization' => 'org-1']);
        $this->assertSame(self::REMOTE_VALUE, $snapshot->getFlag('org-flag'));
        $this->assertSame(self::REMOTE_VALUE, $snapshot->getFlag('mixed-flag'));
        $this->assertCount(1, $this->flagsRequestBodies());

        try {
            FeatureFlag::matchFeatureFlagProperties(
                self::mixedOrgFlag('is_not_set', null),
                'user-1',
                [],
                [],
                null,
                null,
                ['organization' => 'org-1'],
                [],
                ['0' => 'organization']
            );
            $this->fail('Expected InconclusiveMatchException');
        } catch (InconclusiveMatchException $e) {
            $this->assertSame(UnresolvedFlagReason::MISSING_CONTEXT, $e->getReason());
        }
    }

    public function testListedGroupKeyWithoutUsableGroupKeyResolvesFalse(): void
    {
        $groupFlag = self::flag('org-flag', [self::orgPlanFilter('is_not_set', null)], 0);
        $this->makeClient(
            [$groupFlag, self::mixedOrgFlag('is_not_set', null)],
            groupTypeMapping: ['0' => 'organization']
        );

        $snapshot = $this->client->evaluateFlags(
            'user-1',
            groups: ['organization' => ''],
            groupPropertyKeysKnownAbsent: ['organization' => ['plan']]
        );

        $this->assertFalse($snapshot->getFlag('org-flag'));
        $this->assertFalse($snapshot->getFlag('mixed-flag'));
        $this->assertSame([], $this->flagsRequestBodies());
    }

    public function testDirectEvaluateFlagDependencyUsesKeysForPersonDependency(): void
    {
        $dependency = self::flag('profile-incomplete', [self::personFilter('is_not_set', null)]);

        $this->assertTrue(FeatureFlag::evaluateFlagDependency(
            [
                'key' => 'profile-incomplete',
                'type' => 'flag',
                'value' => true,
                'operator' => 'flag_evaluates_to',
                'dependency_chain' => ['profile-incomplete'],
            ],
            ['profile-incomplete' => $dependency],
            [],
            'user-1',
            ['plan' => 'pro'],
            [],
            knownAbsentKeys: ['plan' => true]
        ));
    }
}
