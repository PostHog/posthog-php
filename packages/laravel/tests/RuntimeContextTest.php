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
use PostHog\Laravel\Facades\PostHog as Facade;
use PostHog\Laravel\PostHog;
use PostHog\Laravel\PostHogServiceProvider;
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
