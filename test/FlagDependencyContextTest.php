<?php

// phpcs:disable PSR1.Files.SideEffects
namespace PostHog\Test;

// comment out below to print all logs instead of failing tests
require_once 'test/error_log_mock.php';

use PHPUnit\Framework\TestCase;
use PostHog\Client;
use PostHog\FeatureFlag;
use PostHog\FeatureFlagEvaluations;
use PostHog\InconclusiveMatchException;
use PostHog\PostHog;
use PostHog\UnresolvedFlagReason;

/**
 * A flag dependency is evaluated as if the referenced flag were evaluated directly with the same inputs,
 * using its own aggregation, properties and bucketing identifier (PostHog/sdk-specs#250).
 */
class FlagDependencyContextTest extends TestCase
{
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
        $this->httpClient = new MockedHttpClient(
            'app.posthog.com',
            flagEndpointResponse: [
                'flags' => $flags,
                'group_type_mapping' => ['0' => 'organization'],
                'cohorts' => (object) [],
            ],
            flagsEndpointResponse: ['flags' => []],
        );
        $this->client = new Client('random_key', [], $this->httpClient, 'test-personal-key');
        PostHog::init(null, null, $this->client);
    }

    private static function flag(
        string $key,
        array $properties,
        ?int $aggregation = null,
        int $rolloutPercentage = 100
    ): array {
        $filters = ['groups' => [['properties' => $properties, 'rollout_percentage' => $rolloutPercentage]]];
        if ($aggregation !== null) {
            $filters['aggregation_group_type_index'] = $aggregation;
        }
        return ['id' => 1, 'key' => $key, 'active' => true, 'filters' => $filters];
    }

    private static function dependsOn(string $key): array
    {
        return [
            'key' => $key,
            'type' => 'flag',
            'value' => true,
            'operator' => 'flag_evaluates_to',
            'dependency_chain' => [$key],
        ];
    }

    private static function orgFilter(string $key, string $operator, ?string $value): array
    {
        return ['key' => $key, 'type' => 'group', 'group_type_index' => 0, 'operator' => $operator, 'value' => $value];
    }

    private function remoteRequestCount(): int
    {
        return count(array_filter(
            $this->httpClient->calls ?? [],
            fn ($call) => str_starts_with($call['path'], '/flags/?')
        ));
    }

    private function assertResolvedLocally(FeatureFlagEvaluations $evaluations): void
    {
        $this->assertSame([], $evaluations->getUnresolvedFlags());
        $this->assertSame(0, $this->remoteRequestCount());
    }

    public function testPersonFlagDependencyOnGroupFlagReadsGroupProperties(): void
    {
        $this->makeClient([
            self::flag('org-parent', [self::orgFilter('region', 'exact', 'us')], 0),
            self::flag('person-child', [self::dependsOn('org-parent')]),
        ]);

        $evaluations = $this->client->evaluateFlags(
            'user-1',
            groups: ['organization' => 'acme'],
            personProperties: ['region' => 'us'],
            groupProperties: ['organization' => ['region' => 'eu']],
            onlyEvaluateLocally: true
        );

        $this->assertFalse($evaluations->getFlag('org-parent'));
        $this->assertFalse($evaluations->getFlag('person-child'));
        $this->assertResolvedLocally($evaluations);
    }

    public function testGroupFlagDependencyWithoutItsGroupResolvesAsFalse(): void
    {
        $this->makeClient([
            self::flag('org-parent', [self::orgFilter('tier', 'is_not_set', null)], 0),
            self::flag('person-child', [self::dependsOn('org-parent')]),
        ]);

        $evaluations = $this->client->evaluateFlags(
            'user-1',
            onlyEvaluateLocally: true,
            groupPropertyKeysKnownAbsent: ['organization' => ['tier']]
        );

        $this->assertFalse($evaluations->getFlag('org-parent'));
        $this->assertFalse($evaluations->getFlag('person-child'));
        $this->assertResolvedLocally($evaluations);
    }

    public function testDependencyUsesTheKnownAbsentKeysOfItsOwnAggregation(): void
    {
        $this->makeClient([
            self::flag('org-parent', [self::orgFilter('tier', 'is_not_set', null)], 0),
            self::flag('person-child', [self::dependsOn('org-parent')]),
        ]);

        $evaluations = $this->client->evaluateFlags(
            'user-1',
            groups: ['organization' => 'acme'],
            personProperties: ['tier' => 'gold'],
            groupProperties: ['organization' => ['tier' => 'enterprise']],
            onlyEvaluateLocally: true,
            groupPropertyKeysKnownAbsent: ['organization' => ['tier']]
        );

        $this->assertTrue($evaluations->getFlag('org-parent'));
        $this->assertTrue($evaluations->getFlag('person-child'));
        $this->assertResolvedLocally($evaluations);
    }

    public function testDirectGroupFlagMatchDoesNotUseTheGroupKeyAsThePersonId(): void
    {
        $personParent = self::flag(
            'person-parent',
            [['key' => 'distinct_id', 'type' => 'person', 'operator' => 'exact', 'value' => 'acme']]
        );
        $orgChild = self::flag('org-child', [self::dependsOn('person-parent')], 0);

        try {
            FeatureFlag::matchFeatureFlagProperties(
                $orgChild,
                'acme',
                [],
                [],
                ['person-parent' => $personParent, 'org-child' => $orgChild],
                [],
                ['organization' => 'acme'],
                ['organization' => []],
                ['0' => 'organization']
            );
            $this->fail('Expected InconclusiveMatchException');
        } catch (InconclusiveMatchException $e) {
            $this->assertSame(UnresolvedFlagReason::UNRESOLVED_DEPENDENCY, $e->getReason());
        }
    }

    public function testGroupFlagDependencyBucketsByTheGroupKey(): void
    {
        $this->makeClient([
            self::flag('org-parent', [], 0, 50),
            self::flag('person-child', [self::dependsOn('org-parent')]),
        ]);

        $parentValues = [];
        foreach (['user-1', 'user-2', 'user-3', 'user-4', 'user-5', 'user-6'] as $distinctId) {
            foreach (['org-1', 'org-2', 'globex', 'initech'] as $orgKey) {
                $evaluations = $this->client->evaluateFlags(
                    $distinctId,
                    groups: ['organization' => $orgKey],
                    onlyEvaluateLocally: true
                );

                $parent = $evaluations->getFlag('org-parent');
                $this->assertSame($parent, $evaluations->getFlag('person-child'), "$distinctId in $orgKey");
                $this->assertResolvedLocally($evaluations);
                $parentValues[$parent ? 'true' : 'false'] = true;
            }
        }

        // The 50% rollout must split the organizations, or the test would not tell the hashes apart.
        $this->assertCount(2, $parentValues);
    }

    public function testGroupFlagDependencyOnPersonFlagReadsPersonPropertiesAndDistinctId(): void
    {
        $personParent = self::flag(
            'person-parent',
            [['key' => 'region', 'type' => 'person', 'operator' => 'exact', 'value' => 'us']],
            null,
            50
        );
        $this->makeClient([$personParent, self::flag('org-child', [self::dependsOn('person-parent')], 0)]);

        $parentValues = [];
        foreach (['user-1', 'user-2', 'user-3', 'user-4', 'user-5', 'user-6'] as $distinctId) {
            $evaluations = $this->client->evaluateFlags(
                $distinctId,
                groups: ['organization' => 'acme'],
                personProperties: ['region' => 'us'],
                groupProperties: ['organization' => ['region' => 'eu']],
                onlyEvaluateLocally: true
            );

            $parent = $evaluations->getFlag('person-parent');
            $this->assertSame($parent, $evaluations->getFlag('org-child'), $distinctId);
            $this->assertResolvedLocally($evaluations);
            $parentValues[$parent ? 'true' : 'false'] = true;
        }

        // The person rollout must split the distinct ids, so the dependency can't hash the organization key.
        $this->assertCount(2, $parentValues);
    }
}
