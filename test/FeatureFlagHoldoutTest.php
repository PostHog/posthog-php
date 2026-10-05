<?php

// phpcs:disable PSR1.Files.SideEffects
namespace PostHog\Test;

// comment out below to print all logs instead of failing tests
require_once 'test/error_log_mock.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PostHog\Client;
use PostHog\FeatureFlag;
use PostHog\PostHog;

class FeatureFlagHoldoutTest extends TestCase
{
    private const FAKE_API_KEY = 'random_key';

    public function setUp(): void
    {
        date_default_timezone_set('UTC');
        global $errorMessages;
        $errorMessages = [];
    }

    private static function holdoutFlag($holdout, int $rolloutPercentage = 100, ?string $variant = null): array
    {
        $condition = ['rollout_percentage' => $rolloutPercentage];
        if (!is_null($variant)) {
            $condition['variant'] = $variant;
        }

        return [
            'key' => 'checkout',
            'active' => true,
            'filters' => [
                'holdout' => $holdout,
                'groups' => [$condition],
                'multivariate' => [
                    'variants' => [
                        ['key' => 'control', 'rollout_percentage' => 100],
                        ['key' => 'test', 'rollout_percentage' => 0],
                    ],
                ],
            ],
        ];
    }

    public function testHoldoutWinsOverTargetingAndVariantOverrides(): void
    {
        // Release conditions require an unavailable person property, have rollout 0, and
        // override the variant to "test" — the holdout still decides the flag, and the
        // synthetic variant doesn't need to appear in filters.multivariate.variants.
        $flag = [
            'key' => 'checkout',
            'active' => true,
            'filters' => [
                'holdout' => ['id' => 727, 'exclusion_percentage' => 100],
                'groups' => [
                    [
                        'properties' => [['key' => 'plan', 'value' => 'pro', 'operator' => 'exact']],
                        'rollout_percentage' => 0,
                        'variant' => 'test',
                    ],
                ],
                'multivariate' => [
                    'variants' => [
                        ['key' => 'control', 'rollout_percentage' => 100],
                        ['key' => 'test', 'rollout_percentage' => 0],
                    ],
                ],
            ],
        ];

        self::assertSame('holdout-727', FeatureFlag::matchFeatureFlagProperties($flag, 'user-1', []));
    }

    public function testNoHoldoutPreservesOrdinaryAssignment(): void
    {
        $flag = self::holdoutFlag(null);
        unset($flag['filters']['holdout']);

        self::assertSame('control', FeatureFlag::matchFeatureFlagProperties($flag, 'user-1', []));
        self::assertSame('control', FeatureFlag::matchFeatureFlagProperties(self::holdoutFlag(null), 'user-1', []));
    }

    #[DataProvider('incompleteHoldoutProvider')]
    public function testIncompleteHoldoutPreservesOrdinaryAssignment($holdout): void
    {
        self::assertSame('control', FeatureFlag::matchFeatureFlagProperties(self::holdoutFlag($holdout), 'user-1', []));
    }

    public static function incompleteHoldoutProvider(): array
    {
        return [
            'missing id' => [['exclusion_percentage' => 100]],
            'null id' => [['id' => null, 'exclusion_percentage' => 100]],
            'missing exclusion percentage' => [['id' => 727]],
            'null exclusion percentage' => [['id' => 727, 'exclusion_percentage' => null]],
            'non-numeric exclusion percentage' => [['id' => 727, 'exclusion_percentage' => 'all']],
            'not an object' => ['holdout-727'],
        ];
    }

    public function testPartialMembershipMatchesServerBucketing(): void
    {
        $flag = self::holdoutFlag(['id' => 727, 'exclusion_percentage' => 20]);

        // The holdout hash is taken over "holdout-<bucketing value>", not the ordinary
        // dot-separated flag hash: 0.17805599206573022 for "user-1", 0.6563813925994418
        // for "user-5".
        self::assertSame('holdout-727', FeatureFlag::matchFeatureFlagProperties($flag, 'user-1', []));
        self::assertSame('control', FeatureFlag::matchFeatureFlagProperties($flag, 'user-5', []));
    }

    #[DataProvider('percentageBoundaryProvider')]
    public function testPercentageBoundariesAreInclusiveAndClamped($exclusionPercentage, $expected): void
    {
        // "user-1" hashes to 0.17805599206573022.
        $flag = self::holdoutFlag(['id' => 727, 'exclusion_percentage' => $exclusionPercentage]);

        self::assertSame($expected, FeatureFlag::matchFeatureFlagProperties($flag, 'user-1', []));
    }

    public static function percentageBoundaryProvider(): array
    {
        return [
            'fractional percentage above the hash holds out' => [17.9, 'holdout-727'],
            'fractional percentage below the hash does not' => [17.8, 'control'],
            'integer percentage is not rounded up' => [17, 'control'],
            'zero excludes nobody' => [0, 'control'],
            'negative clamps to zero' => [-10, 'control'],
            'hundred holds out everyone' => [100, 'holdout-727'],
            'above hundred clamps to hundred' => [150, 'holdout-727'],
        ];
    }

    public function testFullHoldoutAppliesWithoutABucketingHash(): void
    {
        $flag = self::holdoutFlag(['id' => 727, 'exclusion_percentage' => 100]);

        self::assertSame('holdout-727', FeatureFlag::matchFeatureFlagProperties($flag, '', []));
    }

    public function testDependencyEvaluationPreservesTheHoldoutValue(): void
    {
        $checkout = self::holdoutFlag(['id' => 727, 'exclusion_percentage' => 100]);
        $dependent = [
            'key' => 'checkout-banner',
            'active' => true,
            'filters' => [
                'groups' => [
                    [
                        'properties' => [
                            [
                                'key' => 'checkout',
                                'type' => 'flag',
                                'operator' => 'flag_evaluates_to',
                                'value' => 'holdout-727',
                                'dependency_chain' => ['checkout'],
                            ],
                        ],
                        'rollout_percentage' => 100,
                    ],
                ],
            ],
        ];

        // The dependency only matches if "checkout" resolved to the holdout variant.
        $evaluationCache = [];
        self::assertTrue(FeatureFlag::matchFeatureFlagProperties(
            $dependent,
            'user-1',
            [],
            [],
            ['checkout' => $checkout],
            $evaluationCache
        ));
    }

    public function testInactiveFlagRemainsDisabled(): void
    {
        $flag = self::holdoutFlag(['id' => 727, 'exclusion_percentage' => 100]);
        $flag['active'] = false;

        $snapshot = $this->localEvaluationSnapshot(['flags' => [$flag]], 'user-1');

        self::assertFalse($snapshot->getFlag('checkout'));
    }

    public function testGroupAggregatedFlagUsesTheGroupKeyForMembership(): void
    {
        $flag = self::holdoutFlag(['id' => 727, 'exclusion_percentage' => 20]);
        $flag['filters']['aggregation_group_type_index'] = 0;

        // The group key buckets into the holdout, the distinct id does not.
        $snapshot = $this->localEvaluationSnapshot(
            ['flags' => [$flag], 'group_type_mapping' => ['0' => 'company']],
            'user-5',
            ['company' => 'user-1']
        );

        self::assertSame('holdout-727', $snapshot->getFlag('checkout'));
    }

    private function localEvaluationSnapshot(array $localFlags, string $distinctId, array $groups = [])
    {
        $httpClient = new MockedHttpClient('app.posthog.com', flagEndpointResponse: $localFlags);
        $client = new Client(self::FAKE_API_KEY, ['debug' => true], $httpClient, 'test');
        PostHog::init(null, null, $client);

        return $client->evaluateFlags(
            $distinctId,
            $groups,
            [],
            $groups === [] ? [] : ['company' => []],
            true
        );
    }
}
