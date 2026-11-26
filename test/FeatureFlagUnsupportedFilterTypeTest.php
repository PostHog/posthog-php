<?php

// phpcs:disable PSR1.Files.SideEffects
namespace PostHog\Test;

require_once 'test/error_log_mock.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PostHog\Client;
use PostHog\FeatureFlag;
use PostHog\InconclusiveMatchException;
use PostHog\RequiresServerEvaluationException;
use PostHog\UnresolvedFlagReason;

/**
 * Local evaluation matches only `person` and `group` filters (and untyped ones) against caller
 * properties. Any other filter type, such as `person_metadata` (a value the flags service reads
 * from the persons table), is inconclusive with the `unsupported_definition` reason.
 */
class FeatureFlagUnsupportedFilterTypeTest extends TestCase
{
    private const FIRST_SEEN_FILTER = [
        'key' => 'created_at',
        'type' => 'person_metadata',
        'operator' => 'is_date_after',
        'value' => '2025-01-01',
    ];
    private const FUTURE_TYPE_FILTER = [
        'key' => 'region',
        'type' => 'future_type',
        'operator' => 'exact',
        'value' => 'eu',
    ];
    private const PLAN_PRO_FILTER = ['key' => 'plan', 'type' => 'person', 'operator' => 'exact', 'value' => 'pro'];
    private const IN_COHORT = ['key' => 'id', 'type' => 'cohort', 'value' => 7];
    private const CREATED_IN_2026 = ['created_at' => '2026-06-01'];

    public function setUp(): void
    {
        global $errorMessages;
        $errorMessages = [];
    }

    private static function flag(array ...$conditions): array
    {
        return [
            'id' => 1,
            'key' => 'first-seen',
            'active' => true,
            'filters' => [
                'groups' => array_map(
                    fn(array $condition) => $condition + ['rollout_percentage' => 100],
                    $conditions
                ),
            ],
        ];
    }

    private static function cohorts(array ...$filters): array
    {
        return ['7' => ['type' => 'AND', 'values' => $filters]];
    }

    private static function evaluate(array $flag, array $properties, array $cohorts = []): bool|string
    {
        return FeatureFlag::matchFeatureFlagProperties($flag, 'user-123', $properties, $cohorts, [], []);
    }

    private function assertInconclusive(callable $evaluate): void
    {
        try {
            $evaluate();
            $this->fail('Expected InconclusiveMatchException');
        } catch (RequiresServerEvaluationException $e) {
            $this->fail('Expected an ordinary inconclusive result, not RequiresServerEvaluationException');
        } catch (InconclusiveMatchException $e) {
            $this->assertSame(UnresolvedFlagReason::UNSUPPORTED_DEFINITION, $e->getReason());
        }
    }

    #[DataProvider('personPropertyContextProvider')]
    public function testPersonMetadataCohortLeafIsInconclusiveRegardlessOfCallerProperties(array $properties): void
    {
        $flag = self::flag(['properties' => [self::IN_COHORT]]);

        $this->assertInconclusive(fn() => self::evaluate($flag, $properties, self::cohorts(self::FIRST_SEEN_FILTER)));
    }

    #[DataProvider('personPropertyContextProvider')]
    public function testPersonMetadataConditionFilterIsInconclusiveRegardlessOfCallerProperties(array $properties): void
    {
        $flag = self::flag(['properties' => [self::FIRST_SEEN_FILTER]]);

        $this->assertInconclusive(fn() => self::evaluate($flag, $properties));
    }

    public static function personPropertyContextProvider(): iterable
    {
        yield 'created_at after the filter date' => [['created_at' => '2026-06-01']];
        yield 'created_at before the filter date' => [['created_at' => '2020-06-01']];
        yield 'without person properties' => [[]];
    }

    #[DataProvider('personFilterTypeProvider')]
    public function testPersonFilterOnCreatedAtStillMatchesCallerProperties(array $type): void
    {
        $filter = array_diff_key(self::FIRST_SEEN_FILTER, ['type' => true]) + $type;

        $this->assertTrue(self::evaluate(self::flag(['properties' => [$filter]]), self::CREATED_IN_2026));
    }

    public static function personFilterTypeProvider(): iterable
    {
        yield 'type person' => [['type' => 'person']];
        yield 'type omitted' => [[]];
        yield 'null type' => [['type' => null]];
        yield 'empty-string type' => [['type' => '']];
    }

    public function testGroupFilterStillMatchesCallerGroupProperties(): void
    {
        $groupFilter = ['type' => 'group', 'group_type_index' => 0] + self::PLAN_PRO_FILTER;
        $flag = self::flag(['properties' => [$groupFilter]]);
        $flag['filters']['aggregation_group_type_index'] = 0;

        // For a group flag, the caller passes the group's properties.
        $this->assertTrue(self::evaluate($flag, ['plan' => 'pro']));
    }

    #[DataProvider('negationProvider')]
    public function testNegationDoesNotTurnUnsupportedCohortLeafIntoMatch(array $leaf, array $cohortFilter): void
    {
        $flag = self::flag(['properties' => [$cohortFilter]]);

        $this->assertInconclusive(fn() => self::evaluate($flag, ['created_at' => '2020-06-01'], self::cohorts($leaf)));
    }

    public static function negationProvider(): iterable
    {
        yield 'leaf negation' => [self::FIRST_SEEN_FILTER + ['negation' => true], self::IN_COHORT];
        yield 'outer not_in' => [self::FIRST_SEEN_FILTER, self::IN_COHORT + ['operator' => 'not_in']];
    }

    public function testAnotherCohortLeafStillDecidesTheGroup(): void
    {
        $flag = self::flag(['properties' => [self::IN_COHORT]]);
        $cohorts = self::cohorts(self::FIRST_SEEN_FILTER, self::PLAN_PRO_FILTER);

        $this->assertFalse(self::evaluate($flag, ['plan' => 'free'] + self::CREATED_IN_2026, $cohorts));
    }

    public function testDefinitivelyFalseFilterDecidesConditionWithUnsupportedFilter(): void
    {
        $flag = self::flag(['properties' => [self::FUTURE_TYPE_FILTER, self::PLAN_PRO_FILTER]]);

        $this->assertFalse(self::evaluate($flag, ['plan' => 'free', 'region' => 'eu']));
    }

    public function testLaterConditionDecidesAfterUnsupportedFilter(): void
    {
        $flag = self::flag(
            ['properties' => [self::FIRST_SEEN_FILTER], 'variant' => 'new-user'],
            ['properties' => [], 'variant' => 'existing-user']
        );
        $flag['filters']['multivariate'] = ['variants' => [
            ['key' => 'new-user', 'rollout_percentage' => 50],
            ['key' => 'existing-user', 'rollout_percentage' => 50],
        ]];

        $this->assertSame('existing-user', self::evaluate($flag, self::CREATED_IN_2026));
    }

    #[DataProvider('unsupportedDefinitionProvider')]
    public function testDefinitionUnresolvedReasonReportsUnsupportedFilterType(array $flag, array $cohorts): void
    {
        $this->assertSame(
            UnresolvedFlagReason::UNSUPPORTED_DEFINITION,
            FeatureFlag::getDefinitionUnresolvedReason($flag, [], $cohorts, [])
        );
    }

    public static function unsupportedDefinitionProvider(): iterable
    {
        yield 'in a condition' => [self::flag(['properties' => [self::FIRST_SEEN_FILTER]]), []];
        yield 'in a cohort' => [
            self::flag(['properties' => [self::IN_COHORT]]),
            self::cohorts(self::FIRST_SEEN_FILTER),
        ];
    }

    public function testUnrecognizedFilterTypeDefersOnlyThatFlag(): void
    {
        $futureType = self::flag(['properties' => [self::FUTURE_TYPE_FILTER + ['key' => 'plan', 'value' => 'pro']]]);
        $futureType['key'] = 'future-type';
        $betaUi = self::flag(['properties' => []]);
        $betaUi['key'] = 'beta-ui';
        $httpClient = new MockedHttpClient(
            'app.posthog.com',
            flagEndpointResponse: ['flags' => [$futureType, $betaUi], 'group_type_mapping' => [], 'cohorts' => []]
        );
        $client = new Client('random_key', [], $httpClient, 'test-personal-key');

        $snapshot = $client->evaluateFlags('user-123', personProperties: ['plan' => 'pro'], onlyEvaluateLocally: true);

        $this->assertSame(['beta-ui'], $snapshot->getKeys());
        $this->assertSame(true, $snapshot->getFlag('beta-ui'));
        $this->assertSame(
            ['future-type' => UnresolvedFlagReason::UNSUPPORTED_DEFINITION],
            $snapshot->getUnresolvedFlags()
        );
    }
}
