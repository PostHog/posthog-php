<?php

namespace PostHog\Laravel\Tests;

use Closure;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase;
use PostHog\Client;
use PostHog\HttpClient;
use PostHog\HttpResponse;
use PostHog\Laravel\Context;
use PostHog\Laravel\ExceptionReporter;
use PostHog\Laravel\Facades\PostHog as Facade;
use PostHog\Laravel\PostHog;
use PostHog\Laravel\PostHogServiceProvider;
use PostHog\Laravel\Tests\Fixtures\FlushClient;
use PostHog\Laravel\Tests\Fixtures\GroupsResolver;
use PostHog\Laravel\Tests\Fixtures\TransportErrorHandler;
use RuntimeException;

class RuntimeContextTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PostHogServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('posthog.api_key', 'project-key');
        $app['config']->set('posthog.sdk.consumer', 'noop');
    }

    public function testThrowingIdentityResolverStillUsesAuthAndGroups(): void
    {
        $this->app->bind('throwing-identity', fn () => fn () => throw new RuntimeException('resolver'));
        $this->app['config']->set('posthog.distinct_id', 'throwing-identity');
        $this->app['config']->set('posthog.groups', GroupsResolver::class);
        $context = $this->app->make(Context::class);
        foreach ([[], ['HTTP_X_POSTHOG_DISTINCT_ID' => 'untrusted']] as $headers) {
            $request = Request::create('/', server: $headers);
            $request->setUserResolver(fn () => new GenericUser(['id' => 'authenticated']));
            $context->fromRequest($request);
            $this->assertSame('authenticated', $context->data()['distinctId']);
            $this->assertSame(['team' => 'resolved-team'], $context->data()['groups']);
            $context->with(['distinctId' => 'explicit'], function () use ($context) {
                $this->assertSame('explicit', $context->data()['distinctId']);
            });
            $request->setUserResolver(fn () => throw new RuntimeException('auth'));
            $this->assertSame(['team' => 'resolved-team'], $context->data()['groups']);
        }
    }

    public function testScopedSessionOverrideReplacesInheritedHeaderSession(): void
    {
        $events = [];
        $this->app['config']->set('posthog.sdk.before_send', function ($event) use (&$events) {
            $events[] = $event;
            return null;
        });
        $context = $this->app->make(Context::class);
        $context->fromRequest(Request::create('/', server: ['HTTP_X_POSTHOG_SESSION_ID' => 'old']));
        $manager = $this->app->make(PostHog::class);
        $context->with(['sessionId' => 'new'], fn () => $manager->capture('scoped', [], 'user'));
        $context->with(
            ['sessionId' => 'new', 'properties' => ['$session_id' => 'explicit']],
            fn () => $manager->capture('explicit', [], 'user'),
        );
        $manager->capture('outer', [], 'user');
        $this->assertSame(
            ['new', 'explicit', 'old'],
            array_map(fn ($event) => $event['properties']['$session_id'], $events),
        );
    }

    public function testAutomaticReportingDefersFlushToLifecycleCleanup(): void
    {
        $client = new FlushClient();
        $manager = $this->app->make(PostHog::class);
        (new \ReflectionProperty(PostHog::class, 'client'))->setValue($manager, $client);
        $this->app->make(ExceptionReporter::class)->report(new RuntimeException('failure'));
        $this->assertSame(0, $client->flushes);
    }

    public function testInvokableErrorHandlerConstructsRealLibCurlConsumer(): void
    {
        $this->app['config']->set('posthog.sdk.consumer', 'lib_curl');
        $this->app['config']->set('posthog.sdk.error_handler', TransportErrorHandler::class);
        // Drop the event before dispatch: construction must still exercise LibCurl/HttpClient.
        $this->app['config']->set('posthog.sdk.before_send', fn () => null);
        $manager = $this->app->make(PostHog::class);
        $this->assertInstanceOf(Client::class, $manager->client());
        $this->assertFalse($manager->capture('dropped', [], 'diagnostic'));
        $this->assertTrue($manager->flush());
        $this->app['config']->set('posthog.sdk.error_handler', Closure::fromCallable(new TransportErrorHandler()));
        $this->assertInstanceOf(Client::class, (new PostHog($this->app))->client());
    }

    public function testDiagnosticSendIsIsolatedAndRestoresBothParents(): void
    {
        $events = [];
        $this->app['config']->set('posthog.sdk.consumer', 'lib_curl');
        $this->app['config']->set('posthog.sdk.before_send', function ($event) use (&$events) {
            $events[] = $event;
            return null;
        });
        $calls = 0;
        $this->app->bind('diagnostic-resolver', function () use (&$calls) {
            return function () use (&$calls) {
                $calls++;
                return 'resolver-private';
            };
        });
        $this->app->bind('diagnostic-groups', function () use (&$calls) {
            return function () use (&$calls) {
                $calls++;
                return ['resolver' => 'private-group'];
            };
        });
        $this->app['config']->set('posthog.distinct_id', 'diagnostic-resolver');
        $this->app['config']->set('posthog.groups', 'diagnostic-groups');
        $manager = $this->app->make(PostHog::class);
        $context = $this->app->make(Context::class);
        $context->begin([
            'distinctId' => 'parent', 'sessionId' => 'private-session',
            'groups' => ['team' => 'private-team'], 'properties' => ['email' => 'private@example.test'],
        ]);
        $before = $context->snapshot();
        $flags = $context->flags('parent', fn () => $manager->evaluateFlags(flagKeys: []));
        $raw = $manager->client()->enterContext(['distinctId' => 'raw-parent', 'properties' => ['raw' => 'private']]);
        $rawBefore = $manager->client()->getContext();
        $calls = 0;
        $this->assertSame(1, Artisan::call('posthog:test'));
        $this->assertSame([], $events);
        // The intentional before_send drop reports failure without dispatching network traffic.
        $this->assertSame(1, Artisan::call('posthog:test', ['--send' => true]));
        $this->assertCount(1, $events);
        // Command boundaries resolve their own snapshot; the diagnostic capture itself must not.
        $calls = 0;
        $command = new \PostHog\Laravel\Commands\TestCommand();
        $command->setLaravel($this->app);
        $command->run(
            new \Symfony\Component\Console\Input\ArrayInput(['--send' => true]),
            new \Symfony\Component\Console\Output\NullOutput(),
        );
        $this->assertSame(0, $calls);
        $this->assertCount(2, $events);
        foreach ($events as $event) {
            $this->assertStringStartsWith('posthog-test-', $event['distinct_id']);
            foreach (['$session_id', '$groups', 'email', 'raw'] as $key) {
                $this->assertArrayNotHasKey($key, $event['properties']);
            }
        }
        $this->assertSame($rawBefore, $manager->client()->getContext());
        $this->assertSame($before, $context->snapshot());
        $this->assertSame($flags, $context->flags('parent', fn () => throw new RuntimeException('lost snapshot')));
        $raw->close();
    }

    public function testManagerScopesBridgeRawClientAndRestoreSnapshots(): void
    {
        $events = [];
        $this->app['config']->set('posthog.sdk.before_send', function ($event) use (&$events) {
            $events[] = $event;
            return $event;
        });
        $manager = $this->app->make(PostHog::class);
        $context = $this->app->make(Context::class);
        $context->begin([
            'distinctId' => 'parent', 'sessionId' => 'parent-session', 'groups' => ['team' => 'parent-team'],
            'properties' => ['parent' => true, 'nested' => ['old' => true]],
        ]);
        $before = $context->snapshot();
        $flags = $context->flags('parent', fn () => $manager->evaluateFlags(flagKeys: []));
        $raw = $manager->client()->enterContext(['distinctId' => 'raw-parent', 'properties' => ['raw' => true]]);
        $rawBefore = $manager->client()->getContext();
        Facade::withContext([
            'distinct_id' => 'scope', 'session_id' => 'scope-session',
            'properties' => ['nested' => ['new' => true]], 'groups' => ['team' => 'scope-team'],
        ], function () use ($manager) {
            $this->assertSame('scope', Facade::getContext()['distinctId']);
            $this->assertSame(Facade::getContext(), $manager->client()->getContext());
            Facade::capture('manager');
            $manager->client()->capture(['event' => 'raw']);
            $this->assertSame(['new' => true], Facade::getContext()['properties']['nested']);
            $this->assertTrue(Facade::getContext()['properties']['raw']);
            $this->assertTrue(Facade::getContext()['properties']['parent']);
            try {
                Facade::withContext(['distinctId' => 'inner'], fn () => throw new RuntimeException('restore'));
            } catch (RuntimeException) {
            }
            $this->assertSame('scope', Facade::getContext()['distinctId']);
        });
        $this->assertSame(['scope', 'scope'], array_column($events, 'distinct_id'));
        $this->assertSame('scope-session', $events[0]['properties']['$session_id']);
        $this->assertSame(['team' => 'scope-team'], $events[0]['properties']['$groups']);
        $this->assertSame($before, $context->snapshot());
        $this->assertSame($rawBefore, $manager->client()->getContext());
        $this->assertSame($flags, $context->flags('parent', fn () => throw new RuntimeException('lost snapshot')));
        $outer = Facade::enterContext(['distinctId' => 'outer']);
        $inner = Facade::enterContext(['distinctId' => 'inner']);
        $outer->close();
        $outer->close();
        $this->assertSame('inner', Facade::getContext()['distinctId']);
        Facade::capture('entered');
        $this->assertSame('inner', $events[2]['distinct_id']);
        $inner->close();
        $inner->close();
        $this->assertSame($before, $context->snapshot());
        $this->assertSame($rawBefore, $manager->client()->getContext());
        $raw->close();
        $this->assertSame('parent', Facade::getContext()['distinctId']);
        $this->assertNull($manager->client()->getContext());
    }

    public function testManagerGetContextOverlaysRawContextWithoutChangingEitherParent(): void
    {
        $manager = $this->app->make(PostHog::class);
        $client = $manager->client();
        $context = $this->app->make(Context::class);
        $context->begin([
            'distinctId' => 'laravel', 'sessionId' => 'laravel-session',
            'properties' => ['shared' => 'laravel', 'laravelOnly' => true, 'nested' => ['laravel' => true]],
            'groups' => ['team' => 'laravel-team'],
        ]);
        $laravelBefore = $context->snapshot();
        $flags = $context->flags('parent', fn () => $manager->evaluateFlags(flagKeys: []));
        $raw = $client->enterContext([
            'distinctId' => 'raw', 'sessionId' => 'raw-session',
            'properties' => ['shared' => 'raw', 'rawOnly' => true, 'nested' => ['raw' => true]],
        ]);
        $rawBefore = $client->getContext();
        try {
            $expected = [
                'distinctId' => 'raw', 'sessionId' => 'raw-session',
                'properties' => [
                    '$session_id' => 'raw-session', 'shared' => 'raw', 'laravelOnly' => true,
                    'nested' => ['raw' => true], 'rawOnly' => true,
                ],
            ];
            $this->assertSame($expected, $manager->getContext());
            $this->assertSame($expected, Facade::getContext());
            $this->assertSame($laravelBefore, $context->snapshot());
            $this->assertSame($rawBefore, $client->getContext());
            $this->assertSame($flags, $context->flags('parent', fn () => throw new RuntimeException('lost snapshot')));
            $this->assertSame($expected, $manager->withContext([], fn () => $client->getContext()));
        } finally {
            $raw->close();
        }
        $this->assertSame([
            'distinctId' => 'laravel', 'sessionId' => 'laravel-session',
            'properties' => [
                '$session_id' => 'laravel-session', 'shared' => 'laravel', 'laravelOnly' => true,
                'nested' => ['laravel' => true],
            ],
        ], $manager->getContext());
        $this->assertSame($laravelBefore, $context->snapshot());
        $this->assertNull($client->getContext());
        $this->assertSame($flags, $context->flags('parent', fn () => throw new RuntimeException('lost snapshot')));
    }

    public function testFreshManagerScopeSuppressesRequestAndResolvers(): void
    {
        $manager = $this->app->make(PostHog::class);
        $context = $this->app->make(Context::class);
        $request = Request::create('/private', server: ['HTTP_X_POSTHOG_DISTINCT_ID' => 'header']);
        $request->setUserResolver(fn () => new GenericUser(['id' => 'auth']));
        $context->fromRequest($request);
        $this->app['config']->set('posthog.groups', GroupsResolver::class);
        $calls = 0;
        $this->app->bind('fresh-resolver', function () use (&$calls) {
            return function () use (&$calls) {
                $calls++;
                return null;
            };
        });
        $this->app['config']->set('posthog.distinct_id', 'fresh-resolver');
        $before = $context->snapshot();
        $calls = 0;
        Facade::withContext([], function () use ($manager, $context, &$calls) {
            $this->assertSame(['distinctId' => null, 'sessionId' => null, 'properties' => []], Facade::getContext());
            $this->assertSame(Facade::getContext(), $manager->client()->getContext());
            $this->assertArrayNotHasKey('groups', $context->data());
            $this->assertSame([], $manager->evaluateFlags()->getKeys());
            $this->assertSame(0, $calls);
        }, ['fresh' => true]);
        $this->assertSame($before, $context->snapshot());
    }

    public function testAutomaticReportingUsesCaptureExceptionAndRestoresContextWhenItThrows(): void
    {
        $manager = $this->getMockBuilder(PostHog::class)
            ->setConstructorArgs([$this->app])
            ->onlyMethods(['captureException'])
            ->getMock();
        $this->app->instance(PostHog::class, $manager);
        $context = $this->app->make(Context::class);
        $context->begin([
            'distinctId' => 'origin', 'sessionId' => 'origin-session',
            'groups' => ['team' => 'origin-team'], 'properties' => ['component' => 'origin'],
        ]);
        $exception = new RuntimeException('original failure');
        $reporter = $this->app->make(ExceptionReporter::class);
        $reporter->remember($exception, 'task');
        $context->begin(['distinctId' => 'later', 'groups' => ['team' => 'later-team']]);
        $before = $context->snapshot();
        $flags = $context->flags('later', fn () => $manager->evaluateFlags(flagKeys: []));
        $raw = $manager->client()->enterContext(['distinctId' => 'raw-later']);
        $rawBefore = $manager->client()->getContext();
        $observed = null;
        $manager->expects($this->once())->method('captureException')->willReturnCallback(
            function ($captured, $distinctId, $properties, $options) use ($manager, $context, &$observed) {
                $observed = [$captured, $context->data(), $manager->client()->getContext(), $options];
                throw new RuntimeException('capture failed');
            },
        );
        try {
            $reporter->report($exception);
            $reporter->report($exception);
            $this->assertNotNull($observed);
            $this->assertSame($exception, $observed[0]);
            $this->assertSame('origin', $observed[1]['distinctId']);
            $this->assertSame('origin-session', $observed[1]['sessionId']);
            $this->assertSame(['team' => 'origin-team'], $observed[1]['groups']);
            $this->assertSame('origin', $observed[1]['properties']['component']);
            $this->assertSame('origin', $observed[2]['distinctId']);
            $this->assertFalse($observed[3]['handled']);
            $this->assertSame('task', $observed[3]['mechanism']);
            $this->assertSame($before, $context->snapshot());
            $this->assertSame($rawBefore, $manager->client()->getContext());
            $this->assertSame($flags, $context->flags('later', fn () => throw new RuntimeException('lost flags')));
        } finally {
            $raw->close();
        }
    }

    public function testKnownExceptionSourceSurvivesLaterBoundariesButDoesNotLeakIntoManualCaptures(): void
    {
        $events = [];
        $this->app['config']->set('posthog.sdk.before_send', function ($event) use (&$events) {
            $events[] = $event;
            return null;
        });
        $context = $this->app->make(Context::class);
        $context->begin(['distinctId' => 'origin']);
        $exception = new RuntimeException('original failure');
        $reporter = $this->app->make(ExceptionReporter::class);
        $reporter->remember($exception, 'task', 'laravel.queue_job');
        $context->begin(['distinctId' => 'later']);
        $reporter->remember($exception, 'runtime', 'laravel.console_lifecycle');
        $handler = $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class);
        $handler->report($exception);
        $handler->report($exception);
        $this->assertCount(1, $events);
        $this->assertSame('origin', $events[0]['distinct_id']);
        $this->assertSame('laravel.queue_job', $events[0]['properties']['$exception_source'] ?? null);
        $this->assertSame('task', $events[0]['properties']['$exception_list'][0]['mechanism']['type']);
        $this->assertSame(['distinctId' => 'later'], $context->snapshot());
        $manager = $this->app->make(PostHog::class);
        $manager->captureException($exception, properties: ['$exception_source' => 'forged']);
        $this->assertCount(2, $events);
        $this->assertSame('later', $events[1]['distinct_id']);
        $this->assertArrayNotHasKey('$exception_source', $events[1]['properties']);
        $reporter->report(new RuntimeException('unknown hook'));
        $this->assertCount(3, $events);
        $this->assertArrayNotHasKey('$exception_source', $events[2]['properties']);
        $this->assertSame(['distinctId' => 'later'], $context->snapshot());
        $this->assertNull($manager->client()->getContext());
    }

    public function testDelayedFlagExposuresKeepOriginAfterRequestSwitchAndEnd(): void
    {
        $events = [];
        $http = $this->createMock(HttpClient::class);
        $http->expects($this->exactly(2))->method('sendRequest')->willReturn(new HttpResponse(json_encode([
            'flags' => ['checkout' => ['enabled' => true, 'variant' => 'test']],
        ]), 200));
        $sdk = new Client('project-key', [
            'consumer' => 'noop',
            'before_send' => function ($event) use (&$events) {
                $events[] = $event;
                return $event;
            },
        ], $http, loadFeatureFlags: false);
        $manager = new class ($this->app, $sdk) extends PostHog {
            public function __construct(Container $app, private Client $testClient)
            {
                parent::__construct($app);
            }

            public function client(): Client
            {
                return $this->testClient;
            }
        };
        $context = $this->app->make(Context::class);
        foreach (['isEnabled', 'getFlag'] as $accessor) {
            $request = Request::create('/origin/123', server: [
                'HTTP_X_POSTHOG_DISTINCT_ID' => 'origin-' . $accessor,
                'HTTP_X_POSTHOG_SESSION_ID' => 'origin-session',
            ]);
            $request->setRouteResolver(fn () => new Route('GET', 'origin/{id}', fn () => null));
            $context->fromRequest($request);
            $flags = $manager->evaluateFlags();
            $this->assertSame($flags, $manager->evaluateFlags());
            $request->headers->set('X-PostHog-Session-Id', 'mutated');
            $context->begin(['distinctId' => 'next', 'sessionId' => 'next-session']);
            $raw = $sdk->enterContext(['distinctId' => 'raw-next', 'sessionId' => 'raw-next-session']);
            $before = $sdk->getContext();
            if ($accessor === 'getFlag') {
                $context->end();
            }
            $flags->$accessor('checkout');
            $flags->only(['checkout'])->$accessor('checkout');
            $flags->$accessor('checkout');
            $this->assertSame($before, $sdk->getContext());
            $raw->close();
        }
        $this->assertCount(2, $events);
        foreach ($events as $event) {
            $this->assertStringStartsWith('origin-', $event['distinct_id']);
            $this->assertSame('origin-session', $event['properties']['$session_id']);
            $this->assertSame('/origin/{id}', $event['properties']['$request_path']);
            $this->assertSame('GET', $event['properties']['$request_method']);
        }
    }
}
