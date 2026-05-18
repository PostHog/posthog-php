<?php

namespace PostHog\Test;

use PHPUnit\Framework\TestCase;
use PostHog\Client;
use PostHog\PostHog;
use PostHog\RequestContext;

class RequestContextTest extends TestCase
{
    use ClockMockTrait;

    private const FAKE_API_KEY = 'random_key';

    private MockedHttpClient $httpClient;
    private Client $client;

    public function setUp(): void
    {
        date_default_timezone_set('UTC');
        RequestContext::reset();
        $this->httpClient = new MockedHttpClient('app.posthog.com');
        $this->client = new Client(
            self::FAKE_API_KEY,
            [
                'debug' => true,
            ],
            $this->httpClient,
            'test'
        );
        PostHog::init(null, null, $this->client);

        global $errorMessages;
        $errorMessages = [];
    }

    public function tearDown(): void
    {
        RequestContext::reset();
    }

    public function testCaptureUsesContextDistinctSessionAndProperties(): void
    {
        PostHog::withContext([
            'distinctId' => 'context-user',
            'sessionId' => 'context-session',
            'properties' => ['plan' => 'pro'],
        ], function (): void {
            PostHog::capture([
                'event' => 'context event',
                'properties' => ['source' => 'test'],
            ]);
        });

        $event = $this->flushAndGetEvents()[0];

        $this->assertSame('context-user', $event['distinct_id']);
        $this->assertSame('context-session', $event['properties']['$session_id']);
        $this->assertSame('pro', $event['properties']['plan']);
        $this->assertSame('test', $event['properties']['source']);
        $this->assertArrayNotHasKey('$process_person_profile', $event['properties']);
    }

    public function testExplicitCaptureValuesOverrideContext(): void
    {
        PostHog::withContext([
            'distinctId' => 'context-user',
            'sessionId' => 'context-session',
            'properties' => ['plan' => 'free', 'region' => 'us'],
        ], function (): void {
            PostHog::capture([
                'distinctId' => 'explicit-user',
                'event' => 'override event',
                'properties' => [
                    '$session_id' => 'explicit-session',
                    'plan' => 'paid',
                ],
            ]);
        });

        $event = $this->flushAndGetEvents()[0];

        $this->assertSame('explicit-user', $event['distinct_id']);
        $this->assertSame('explicit-session', $event['properties']['$session_id']);
        $this->assertSame('paid', $event['properties']['plan']);
        $this->assertSame('us', $event['properties']['region']);
    }

    public function testInvalidCamelDistinctIdFallsBackToValidSnakeDistinctId(): void
    {
        PostHog::withContext(['distinctId' => 'context-user'], function (): void {
            PostHog::capture([
                'distinctId' => '',
                'distinct_id' => 'snake-user',
                'event' => 'snake fallback event',
            ]);
        });

        $event = $this->flushAndGetEvents()[0];

        $this->assertSame('snake-user', $event['distinct_id']);
        $this->assertArrayNotHasKey('$process_person_profile', $event['properties']);
    }

    public function testMissingDistinctIdCreatesPersonlessEvent(): void
    {
        PostHog::capture([
            'event' => 'personless event',
            'properties' => ['plan' => 'free'],
        ]);

        $event = $this->flushAndGetEvents()[0];

        $this->assertIsString($event['distinct_id']);
        $this->assertNotSame('', $event['distinct_id']);
        $this->assertFalse($event['properties']['$process_person_profile']);
        $this->assertSame('free', $event['properties']['plan']);
    }

    public function testNestedContextInheritanceFreshContextAndRestoration(): void
    {
        PostHog::withContext([
            'distinctId' => 'outer-user',
            'sessionId' => 'outer-session',
            'properties' => ['outer' => true, 'shared' => 'outer'],
        ], function (): void {
            PostHog::withContext([
                'properties' => ['inner' => true, 'shared' => 'inner'],
            ], function (): void {
                PostHog::capture(['event' => 'inherited event']);
            });

            PostHog::withContext([
                'properties' => ['fresh' => true],
            ], function (): void {
                PostHog::capture(['event' => 'fresh event']);
            }, ['fresh' => true]);

            PostHog::capture(['event' => 'restored event']);
        });

        $events = $this->flushAndGetEvents();
        [$inherited, $fresh, $restored] = $events;

        $this->assertSame('outer-user', $inherited['distinct_id']);
        $this->assertSame('outer-session', $inherited['properties']['$session_id']);
        $this->assertTrue($inherited['properties']['outer']);
        $this->assertTrue($inherited['properties']['inner']);
        $this->assertSame('inner', $inherited['properties']['shared']);

        $this->assertNotSame('outer-user', $fresh['distinct_id']);
        $this->assertArrayNotHasKey('$session_id', $fresh['properties']);
        $this->assertArrayNotHasKey('outer', $fresh['properties']);
        $this->assertTrue($fresh['properties']['fresh']);
        $this->assertFalse($fresh['properties']['$process_person_profile']);

        $this->assertSame('outer-user', $restored['distinct_id']);
        $this->assertSame('outer-session', $restored['properties']['$session_id']);
        $this->assertTrue($restored['properties']['outer']);
        $this->assertArrayNotHasKey('inner', $restored['properties']);
    }

    public function testWithContextRestoresAfterException(): void
    {
        try {
            PostHog::withContext(['distinctId' => 'leaky-user'], function (): void {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }

        $this->assertNull(PostHog::getContext());

        PostHog::capture(['event' => 'after exception']);
        $event = $this->flushAndGetEvents()[0];

        $this->assertNotSame('leaky-user', $event['distinct_id']);
        $this->assertFalse($event['properties']['$process_person_profile']);
    }

    public function testContextIsFiberScoped(): void
    {
        $seen = [];

        $fiber = new \Fiber(function () use (&$seen): void {
            PostHog::withContext(['distinctId' => 'fiber-user'], function () use (&$seen): void {
                $seen['fiber-before'] = PostHog::getContext()['distinctId'] ?? null;
                \Fiber::suspend();
                $seen['fiber-after'] = PostHog::getContext()['distinctId'] ?? null;
            });
        });

        $fiber->start();

        PostHog::withContext(['distinctId' => 'main-user'], function () use (&$seen, $fiber): void {
            $seen['main-before'] = PostHog::getContext()['distinctId'] ?? null;
            $fiber->resume();
            $seen['main-after'] = PostHog::getContext()['distinctId'] ?? null;
        });

        $this->assertSame('fiber-user', $seen['fiber-before']);
        $this->assertSame('fiber-user', $seen['fiber-after']);
        $this->assertSame('main-user', $seen['main-before']);
        $this->assertSame('main-user', $seen['main-after']);
        $this->assertNull(PostHog::getContext());
    }

    public function testCaptureContextIsFiberScoped(): void
    {
        $fiber = new \Fiber(function (): void {
            $this->client->withContext([
                'distinctId' => 'fiber-user',
                'properties' => ['scope' => 'fiber'],
            ], function (): void {
                $this->client->capture(['event' => 'fiber before']);
                \Fiber::suspend();
                $this->client->capture(['event' => 'fiber after']);
            });
        });

        $fiber->start();

        $this->client->withContext([
            'distinctId' => 'main-user',
            'properties' => ['scope' => 'main'],
        ], function () use ($fiber): void {
            $this->client->capture(['event' => 'main before']);
            $fiber->resume();
            $this->client->capture(['event' => 'main after']);
        });

        $events = [];
        foreach ($this->flushAndGetEvents() as $event) {
            $events[$event['event']] = $event;
        }

        $this->assertSame('fiber-user', $events['fiber before']['distinct_id']);
        $this->assertSame('fiber', $events['fiber before']['properties']['scope']);
        $this->assertSame('fiber-user', $events['fiber after']['distinct_id']);
        $this->assertSame('fiber', $events['fiber after']['properties']['scope']);

        $this->assertSame('main-user', $events['main before']['distinct_id']);
        $this->assertSame('main', $events['main before']['properties']['scope']);
        $this->assertSame('main-user', $events['main after']['distinct_id']);
        $this->assertSame('main', $events['main after']['properties']['scope']);

        $this->assertNull($this->client->getContext());
    }

    public function testContextFromHeadersSanitizesFrontendTracingHeadersOnly(): void
    {
        $longDistinctId = str_repeat('a', 1200);

        $context = PostHog::contextFromHeaders([
            'x-posthog-distinct-id' => "  {$longDistinctId}\nignored  ",
            'X-POSTHOG-SESSION-ID' => "\t session-123 \r\n",
            'USER_AGENT' => "Agent\x00Name",
            'X-Forwarded-For' => '203.0.113.10, 10.0.0.1',
        ]);

        $this->assertSame(str_repeat('a', 1000), $context['distinctId']);
        $this->assertSame('session-123', $context['sessionId']);
        $this->assertSame('session-123', $context['properties']['$session_id']);
        $this->assertArrayNotHasKey('$current_url', $context['properties']);
        $this->assertArrayNotHasKey('$user_agent', $context['properties']);
        $this->assertArrayNotHasKey('$ip', $context['properties']);

        PostHog::withContext($context, function (): void {
            PostHog::capture(['event' => 'header event']);
        });

        $event = $this->flushAndGetEvents()[0];

        $this->assertSame(str_repeat('a', 1000), $event['distinct_id']);
        $this->assertSame('session-123', $event['properties']['$session_id']);
    }

    public function testContextCanBeCombinedWithTrustedFrameworkMetadata(): void
    {
        $context = PostHog::contextFromHeaders([
            'x-posthog-distinct-id' => 'framework-user',
            'x-posthog-session-id' => 'framework-session',
        ]);
        $context['properties'] = array_merge($context['properties'], [
            '$current_url' => 'https://example.com/api/projects?limit=1',
            '$request_method' => 'GET',
            '$request_path' => '/api/projects',
            '$user_agent' => 'AgentName',
            '$ip' => '203.0.113.10',
        ]);

        PostHog::withContext($context, function (): void {
            PostHog::capture(['event' => 'framework event']);
        });

        $event = $this->flushAndGetEvents()[0];

        $this->assertSame('framework-user', $event['distinct_id']);
        $this->assertSame('framework-session', $event['properties']['$session_id']);
        $this->assertSame('https://example.com/api/projects?limit=1', $event['properties']['$current_url']);
        $this->assertSame('GET', $event['properties']['$request_method']);
        $this->assertSame('/api/projects', $event['properties']['$request_path']);
        $this->assertSame('AgentName', $event['properties']['$user_agent']);
        $this->assertSame('203.0.113.10', $event['properties']['$ip']);
    }

    public function testIntegrationCanSkipTracingHeadersAndStillWrapRequestMetadata(): void
    {
        PostHog::withContext([
            'properties' => [
                '$request_path' => '/api/disabled-tracing',
            ],
        ], function (): void {
            PostHog::capture(['event' => 'disabled tracing headers event']);
        });

        $event = $this->flushAndGetEvents()[0];

        $this->assertFalse($event['properties']['$process_person_profile']);
        $this->assertSame('/api/disabled-tracing', $event['properties']['$request_path']);
        $this->assertArrayNotHasKey('$session_id', $event['properties']);
    }

    public function testEmptyAndNonStringHeaderValuesAreIgnored(): void
    {
        $context = PostHog::contextFromHeaders([
            'X-POSTHOG-DISTINCT-ID' => " \n\r\t ",
            'X-POSTHOG-SESSION-ID' => "\x00\x7F",
        ]);

        $this->assertNull($context['distinctId']);
        $this->assertNull($context['sessionId']);
        $this->assertArrayNotHasKey('$session_id', $context['properties']);
    }

    public function testPhpServerNormalizedPostHogHeadersAreRecognized(): void
    {
        $context = PostHog::contextFromHeaders([
            'HTTP_X_POSTHOG_DISTINCT_ID' => 'server-user',
            'HTTP_X_POSTHOG_SESSION_ID' => 'server-session',
        ]);

        $this->assertSame('server-user', $context['distinctId']);
        $this->assertSame('server-session', $context['sessionId']);
        $this->assertSame('server-session', $context['properties']['$session_id']);
    }

    public function testSymfonyAndLaravelHeaderBagArraysAreRecognized(): void
    {
        $context = PostHog::contextFromHeaders([
            'x-posthog-distinct-id' => ['bag-user'],
            'x-posthog-session-id' => ['bag-session'],
        ]);

        $this->assertSame('bag-user', $context['distinctId']);
        $this->assertSame('bag-session', $context['sessionId']);
        $this->assertSame('bag-session', $context['properties']['$session_id']);
    }

    public function testCaptureExceptionUsesContextAndExplicitDistinctOverride(): void
    {
        PostHog::withContext([
            'distinctId' => 'context-user',
            'sessionId' => 'context-session',
            'properties' => ['$request_path' => '/api/context'],
        ], function (): void {
            PostHog::captureException(new \RuntimeException('context exception'));
            PostHog::captureException(new \RuntimeException('explicit exception'), 'explicit-user', [
                '$session_id' => 'explicit-session',
            ]);
        });

        $events = $this->flushAndGetEvents();

        $this->assertSame('context-user', $events[0]['distinct_id']);
        $this->assertSame('context-session', $events[0]['properties']['$session_id']);
        $this->assertSame('/api/context', $events[0]['properties']['$request_path']);
        $this->assertArrayHasKey('$exception_list', $events[0]['properties']);
        $this->assertArrayNotHasKey('$process_person_profile', $events[0]['properties']);

        $this->assertSame('explicit-user', $events[1]['distinct_id']);
        $this->assertSame('explicit-session', $events[1]['properties']['$session_id']);
    }

    public function testContextDoesNotMutateIdentifyOrAliasIdentity(): void
    {
        PostHog::withContext([
            'distinctId' => 'context-user',
            'sessionId' => 'context-session',
            'properties' => ['context_property' => 'context-value'],
        ], function (): void {
            $this->client->identify([
                'distinctId' => 'identified-user',
                'properties' => ['email' => 'max@example.com'],
            ]);
            $this->client->alias([
                'distinctId' => 'previous-user',
                'alias' => 'next-user',
            ]);
        });

        $events = $this->flushAndGetEvents();

        $this->assertSame('identified-user', $events[0]['distinct_id']);
        $this->assertArrayNotHasKey('context_property', $events[0]['properties']);
        $this->assertArrayNotHasKey('$session_id', $events[0]['properties']);

        $this->assertNull($events[1]['distinct_id']);
        $this->assertSame('previous-user', $events[1]['properties']['distinct_id']);
        $this->assertSame('next-user', $events[1]['properties']['alias']);
        $this->assertArrayNotHasKey('context_property', $events[1]['properties']);
        $this->assertArrayNotHasKey('$session_id', $events[1]['properties']);
    }

    public function testContextDoesNotMutateGroupIdentifyProperties(): void
    {
        PostHog::withContext([
            'distinctId' => 'context-user',
            'sessionId' => 'context-session',
            'properties' => ['context_property' => 'context-value'],
        ], function (): void {
            PostHog::groupIdentify([
                'groupType' => 'organization',
                'groupKey' => 'acme',
                'properties' => ['name' => 'Acme Inc.'],
            ]);
        });

        $event = $this->flushAndGetEvents()[0];

        $this->assertSame('$organization_acme', $event['distinct_id']);
        $this->assertSame('$groupidentify', $event['event']);
        $this->assertSame('organization', $event['properties']['$group_type']);
        $this->assertSame('acme', $event['properties']['$group_key']);
        $this->assertSame(['name' => 'Acme Inc.'], $event['properties']['$group_set']);
        $this->assertArrayNotHasKey('context_property', $event['properties']);
        $this->assertArrayNotHasKey('$session_id', $event['properties']);
    }

    public function testEvaluateFlagsUsesContextDistinctIdWhenOmitted(): void
    {
        PostHog::withContext(['distinctId' => 'context-user'], function (): void {
            PostHog::evaluateFlags();
        });

        $calls = $this->httpClient->calls ?? [];
        $this->assertNotEmpty($calls);
        $payload = json_decode($calls[array_key_last($calls)]['payload'], true);

        $this->assertSame('context-user', $payload['distinct_id']);
    }

    public function testEvaluateFlagsWithoutDistinctIdLogsWarningAndReturnsEmptySnapshot(): void
    {
        $flags = PostHog::evaluateFlags();

        $this->assertSame([], $flags->getKeys());

        global $errorMessages;
        $this->assertContains(
            '[PostHog][Client] evaluateFlags() requires distinctId — pass it explicitly or use withContext().',
            $errorMessages
        );
    }

    public function testPersonlessCaptureDoesNotEvaluateFeatureFlagsFromGeneratedDistinctId(): void
    {
        $deprecations = $this->captureDeprecations(fn() => PostHog::capture([
            'event' => 'personless flags event',
            'send_feature_flags' => true,
        ]));

        $this->assertCount(1, $deprecations);

        $event = $this->flushAndGetEvents()[0];

        $this->assertFalse($event['properties']['$process_person_profile']);
        $this->assertArrayNotHasKey('$active_feature_flags', $event['properties']);
        $this->assertArrayNotHasKey('$feature/true-flag', $event['properties']);

        $flagRequests = array_values(array_filter(
            $this->httpClient->calls ?? [],
            static fn(array $call): bool => str_starts_with($call['path'], '/flags/?')
        ));
        $this->assertCount(0, $flagRequests);
    }

    public function testClientContextIsScopedPerClient(): void
    {
        $otherHttpClient = new MockedHttpClient('app.posthog.com');
        $otherClient = new Client(
            self::FAKE_API_KEY,
            ['debug' => true],
            $otherHttpClient,
            null,
            false
        );

        $this->client->withContext([
            'distinctId' => 'client-a-user',
            'sessionId' => 'client-a-session',
            'properties' => ['client_property' => 'client-a'],
        ], function () use ($otherClient): void {
            $this->client->capture(['event' => 'client a event']);
            $otherClient->capture(['event' => 'client b event']);
        });

        $this->client->flush();
        $otherClient->flush();

        $clientAEvent = $this->eventsFromLastBatchCall($this->httpClient)[0];
        $clientBEvent = $this->eventsFromLastBatchCall($otherHttpClient)[0];

        $this->assertSame('client-a-user', $clientAEvent['distinct_id']);
        $this->assertSame('client-a-session', $clientAEvent['properties']['$session_id']);
        $this->assertSame('client-a', $clientAEvent['properties']['client_property']);

        $this->assertNotSame('client-a-user', $clientBEvent['distinct_id']);
        $this->assertFalse($clientBEvent['properties']['$process_person_profile']);
        $this->assertArrayNotHasKey('$session_id', $clientBEvent['properties']);
        $this->assertArrayNotHasKey('client_property', $clientBEvent['properties']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function flushAndGetEvents(): array
    {
        PostHog::flush();
        return $this->eventsFromLastBatchCall($this->httpClient);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function eventsFromLastBatchCall(MockedHttpClient $httpClient): array
    {
        $batchCalls = array_values(array_filter(
            $httpClient->calls ?? [],
            static fn(array $call): bool => $call['path'] === '/batch/'
        ));
        $this->assertNotEmpty($batchCalls);

        $lastCall = $batchCalls[array_key_last($batchCalls)];
        $payload = json_decode($lastCall['payload'], true);
        $this->assertIsArray($payload);

        return $payload['batch'];
    }

    /**
     * @return list<string>
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
}
