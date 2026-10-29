<?php

namespace PostHog\Test;

use Fiber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PostHog\Client;
use RuntimeException;

class FrameworkIntegrationTest extends TestCase
{
    private MockedHttpClient $http;

    private function client(array $options = []): Client
    {
        $this->http = new MockedHttpClient('localhost');
        return new Client('test-key', $options, $this->http, loadFeatureFlags: false);
    }

    private function events(Client $client): array
    {
        $client->flush();
        $events = [];
        foreach ($this->http->calls ?? [] as $call) {
            if ($call['path'] === '/batch/') {
                $events = array_merge($events, json_decode($call['payload'], true)['batch']);
            }
        }
        return $events;
    }

    public function testInstanceGroupIdentifyDoesNotInheritRequestIdentity(): void
    {
        $client = $this->client();
        $client->withContext(
            ['distinctId' => 'request-user', 'properties' => ['secret' => 'private']],
            function () use ($client) {
                $client->groupIdentify([
                    'groupType' => 'company', 'groupKey' => 'acme', 'properties' => ['plan' => 'pro'],
                ]);
            }
        );
        $event = $this->events($client)[0];
        self::assertSame('$groupidentify', $event['event']);
        self::assertSame('$company_acme', $event['distinct_id']);
        self::assertSame('acme', $event['properties']['$group_key']);
        self::assertSame(['plan' => 'pro'], $event['properties']['$group_set']);
        self::assertArrayNotHasKey('secret', $event['properties']);
    }

    public function testDetachedScopesRestoreOuterContextAndCloseOnlyOnce(): void
    {
        $client = $this->client();
        $outer = $client->enterContext(['distinctId' => 'outer']);
        $inner = $client->enterContext(['distinctId' => 'inner'], ['fresh' => true]);
        $client->capture(['event' => 'inside']);
        $inner->close();
        $inner->close();
        $client->capture(['event' => 'outside']);
        $outer->close();
        self::assertNull($client->getContext());
        self::assertSame(['inner', 'outer'], array_column($this->events($client), 'distinct_id'));
    }

    public function testClosingOuterScopeDoesNotRemoveAYoungerScope(): void
    {
        $client = $this->client();
        $outer = $client->enterContext(['distinctId' => 'outer']);
        $inner = $client->enterContext(['distinctId' => 'inner'], ['fresh' => true]);
        $outer->close();
        self::assertSame('inner', $client->getContext()['distinctId']);
        $inner->close();
        self::assertNull($client->getContext());
    }

    public function testClosingScopeFromAnotherFiberDoesNotClearItsContext(): void
    {
        $client = $this->client();
        $fiber = new Fiber(function () use ($client) {
            $scope = $client->enterContext(['distinctId' => 'fiber-user']);
            Fiber::suspend($scope);
            self::assertNull($client->getContext());
        });
        $fiberScope = $fiber->start();
        $mainScope = $client->enterContext(['distinctId' => 'main-user']);
        $fiberScope->close();
        self::assertSame('main-user', $client->getContext()['distinctId']);
        $fiber->resume();
        $mainScope->close();
        self::assertNull($client->getContext());
    }

    public function testScopeDestructionClosesItsContext(): void
    {
        $client = $this->client();
        $scope = $client->enterContext(['distinctId' => 'temporary']);
        unset($scope);
        self::assertNull($client->getContext());
    }

    public function testAbandonedFiberRunsContextCleanupWithoutCyclicCollection(): void
    {
        $client = $this->client();
        $finished = false;
        $gcEnabled = gc_enabled();
        gc_disable();
        try {
            $fiber = new Fiber(function () use ($client, &$finished): void {
                try {
                    $client->withContext(['distinctId' => 'abandoned'], static function (): void {
                        Fiber::suspend();
                    });
                } finally {
                    $finished = true;
                }
            });
            $fiber->start();
            $reference = \WeakReference::create($fiber);
            unset($fiber);

            self::assertTrue($finished, 'Dropping a suspended Fiber must run its finally blocks immediately');
            self::assertNull($reference->get());
        } finally {
            gc_collect_cycles();
            if ($gcEnabled) {
                gc_enable();
            }
        }
    }

    public function testDetachedScopeDoesNotLeakIntoAReplacementFiber(): void
    {
        $client = $this->client();
        $read = static function () use ($client): void {
            self::assertNull($client->getContext());
        };
        $enter = static fn () => Fiber::suspend($client->enterContext(['distinctId' => 'abandoned']));
        $fiber = new Fiber($enter);
        $scope = $fiber->start();
        try {
            unset($fiber);
            (new Fiber($read))->start();
        } finally {
            $scope->close();
        }
    }

    public static function exceptionMetadataCases(): array
    {
        return [
            'empty mechanism' => [['mechanism' => '', 'handled' => false], 'generic', false],
            'whitespace mechanism' => [['mechanism' => " \t", 'handled' => false], 'generic', false],
            'numeric mechanism' => [['mechanism' => 123, 'handled' => false], 'generic', false],
            'array mechanism' => [['mechanism' => [], 'handled' => false], 'generic', false],
            'null mechanism' => [['mechanism' => null, 'handled' => false], 'generic', false],
            'string handled' => [['mechanism' => 'task', 'handled' => 'false'], 'task', true],
            'numeric handled' => [['mechanism' => 'task', 'handled' => 0], 'task', true],
            'array handled' => [['mechanism' => 'task', 'handled' => []], 'task', true],
            'null handled' => [['mechanism' => 'task', 'handled' => null], 'task', true],
            'valid overrides' => [['mechanism' => 'auto.framework', 'handled' => false], 'auto.framework', false],
            'nonempty zero mechanism' => [['mechanism' => '0', 'handled' => false], '0', false],
        ];
    }

    #[DataProvider('exceptionMetadataCases')]
    public function testExceptionMetadataOverridesAreValidatedIndependently(
        array $options,
        string $expectedMechanism,
        bool $expectedHandled
    ): void {
        $client = $this->client();
        self::assertTrue($client->captureException(new RuntimeException('error'), 'user', [], $options));
        $properties = $this->events($client)[0]['properties'];
        self::assertSame($expectedMechanism, $properties['$exception_list'][0]['mechanism']['type']);
        self::assertSame($expectedHandled, $properties['$exception_list'][0]['mechanism']['handled']);
        self::assertSame($expectedHandled, $properties['$exception_handled']);
    }

    public function testFrameworkExceptionOptionsPreserveChainAndStack(): void
    {
        $client = $this->client();
        $exception = new RuntimeException('outer', previous: new RuntimeException('root'));
        $client->captureException($exception, 'user', ['$exception_list' => [], '$exception_handled' => true], [
            'handled' => false,
            'mechanism' => 'auto.framework',
        ]);
        $event = $this->events($client)[0];
        self::assertFalse($event['properties']['$exception_handled']);
        $list = $event['properties']['$exception_list'];
        self::assertCount(2, $list);
        self::assertSame('outer', $list[0]['value']);
        self::assertSame('root', $list[1]['value']);
        self::assertSame(['type' => 'auto.framework', 'handled' => false], $list[0]['mechanism']);
        self::assertTrue($list[1]['mechanism']['handled']);
        self::assertNotEmpty($list[0]['stacktrace']['frames']);
    }

    public function testManualExceptionsStayHandledByDefault(): void
    {
        $client = $this->client();
        $client->captureException(new RuntimeException('handled'));
        $event = $this->events($client)[0];
        self::assertTrue($event['properties']['$exception_handled']);
        self::assertSame('generic', $event['properties']['$exception_list'][0]['mechanism']['type']);
    }

    public function testConfiguredLibraryAndReleaseApplyAcrossEventTypes(): void
    {
        $client = $this->client([
            'library_name' => 'posthog-laravel', 'library_version' => '0.1.0', 'release_id' => 'release',
        ]);
        $client->capture(['event' => 'purchase']);
        $client->identify(['distinctId' => 'user', 'properties' => ['plan' => 'pro']]);
        $client->alias(['distinctId' => 'user', 'alias' => 'old-user']);
        $client->groupIdentify(['groupType' => 'company', 'groupKey' => 'acme']);
        $client->captureException('error');
        $events = $this->events($client);
        self::assertCount(5, $events);
        foreach ($events as $event) {
            self::assertSame('posthog-laravel', $event['properties']['$lib']);
            self::assertSame('0.1.0', $event['properties']['$lib_version']);
            self::assertSame('release', $event['properties']['$release_id']);
        }
    }

    public function testConfiguredLibraryIsUsedForFlagHttpRequests(): void
    {
        $client = $this->client(['library_name' => 'posthog-laravel', 'library_version' => '0.1.0']);
        $client->evaluateFlags('user');
        self::assertContains('User-Agent: posthog-laravel/0.1.0', $this->http->calls[0]['extraHeaders']);
    }

    public function testReleaseOptionOverridesEnvironmentAndNullDisablesIt(): void
    {
        $previous = getenv('POSTHOG_RELEASE_ID');
        putenv('POSTHOG_RELEASE_ID=environment-release');
        try {
            $client = $this->client(['release_id' => 'configured-release']);
            $client->capture(['event' => 'configured']);
            self::assertSame('configured-release', $this->events($client)[0]['properties']['$release_id']);
            $client = $this->client(['release_id' => null]);
            $client->capture(['event' => 'disabled']);
            self::assertArrayNotHasKey('$release_id', $this->events($client)[0]['properties']);
        } finally {
            putenv($previous === false ? 'POSTHOG_RELEASE_ID' : 'POSTHOG_RELEASE_ID=' . $previous);
        }
    }

    public function testExplicitEventMetadataWinsOverClientOptions(): void
    {
        $client = $this->client([
            'library_name' => 'integration', 'library_version' => '0.1.0', 'release_id' => 'default',
        ]);
        $client->capture(['event' => 'custom', 'properties' => [
            '$lib' => 'custom', '$lib_version' => '2.0.0', '$release_id' => 'explicit',
        ]]);
        $properties = $this->events($client)[0]['properties'];
        self::assertSame('custom', $properties['$lib']);
        self::assertSame('2.0.0', $properties['$lib_version']);
        self::assertSame('explicit', $properties['$release_id']);
    }
}
