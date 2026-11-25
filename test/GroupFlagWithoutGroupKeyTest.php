<?php

// phpcs:disable PSR1.Files.SideEffects
namespace PostHog\Test;

// comment out below to print all logs instead of failing tests
require_once 'test/error_log_mock.php';

use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use PostHog\Client;
use PostHog\FeatureFlag;
use PostHog\InconclusiveMatchException;
use PostHog\PostHog;
use PostHog\UnresolvedFlagReason;

/**
 * Like the flags service, a group flag evaluated without a key for its group type resolves false locally,
 * and a group condition without its group key is skipped.
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
}
