<?php

namespace PostHog\Laravel\Tests;

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PostHog\Laravel\Autocapture\Collector;
use PostHog\Laravel\Context;
use PostHog\Laravel\ExceptionReporter;
use PostHog\Laravel\Facades\PostHog;
use PostHog\Laravel\PostHogServiceProvider;
use PostHog\Laravel\QueueContext;
use PostHog\Laravel\Tests\Fixtures\CollectedRecord;
use PostHog\Laravel\Tests\Fixtures\OrderCompleted;
use RuntimeException;

class RequestIpTest extends TestCase
{
    private array $events = [];

    protected function getPackageProviders($app): array
    {
        return [PostHogServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('posthog.api_key', 'project-key');
        $app['config']->set('posthog.sdk.consumer', 'noop');
        $app['config']->set('posthog.sdk.before_send', function ($event) {
            $this->events[] = $event;
            return null;
        });
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:']);
        $app['config']->set('posthog.autocapture.domain_events', [OrderCompleted::class => [
            'event' => 'order_completed', 'properties' => ['amount' => 'amount'],
        ]]);
    }

    private function request(string $ip = '198.51.100.23', string $id = 'actor'): Request
    {
        $request = Request::create('/', server: [
            'REMOTE_ADDR' => $ip, 'HTTP_X_POSTHOG_DISTINCT_ID' => $id,
            'HTTP_X_POSTHOG_SESSION_ID' => 'session',
        ]);
        $this->app->make(Context::class)->fromRequest($request);
        return $request;
    }

    #[DataProvider('proxyConfigurations')]
    public function testRequestIpUsesLaravelTrustedProxyRules(array $trusted, string $remote, string $expected): void
    {
        $previous = Request::getTrustedProxies();
        $previousHeaders = Request::getTrustedHeaderSet();
        try {
            Request::setTrustedProxies($trusted, Request::HEADER_X_FORWARDED_FOR);
            $request = $this->request($remote);
            $request->headers->set('X-Forwarded-For', '203.0.113.42');
            $request->headers->set('CF-Connecting-IP', '203.0.113.99');
            PostHog::capture('request');
            $this->assertSame($expected, $this->events[0]['properties']['$ip'] ?? null);
            $this->assertTrue($this->events[0]['properties']['$geoip_disable']);
        } finally {
            Request::setTrustedProxies($previous, $previousHeaders);
        }
    }

    public static function proxyConfigurations(): array
    {
        return [
            'untrusted forwarded header' => [[], '10.0.0.1', '10.0.0.1'],
            'trusted proxy' => [['10.0.0.1'], '10.0.0.1', '203.0.113.42'],
            'IPv6 peer' => [[], '2001:db8::1', '2001:db8::1'],
        ];
    }

    public function testCapturesExceptionsAndCollectorsIncludeIpAndRespectExplicitOverrides(): void
    {
        foreach (['requests', 'pageviews', 'auth'] as $section) {
            $this->app['config']->set('posthog.autocapture.' . $section . '.enabled', true);
        }
        $this->app['config']->set('app.url', 'https://app.example');
        Route::get('/ip', function () {
            PostHog::capture('manual');
            PostHog::capture('explicit', ['$ip' => '203.0.113.10']);
            PostHog::capture('redacted', ['$ip' => null]);
            PostHog::captureException(new RuntimeException('handled'));
            $this->app['events']->dispatch(new OrderCompleted());
            $this->app['events']->dispatch(new Login('web', new GenericUser(['id' => 'actor']), false));
            Collector::capture('explicit collector', ['$ip' => null]);
            app()->terminating(fn () => PostHog::capture('after response'));
            return response('<p>ok</p>')->header('Content-Type', 'text/html');
        });
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.23'])->get('/ip', [
            'X-PostHog-Distinct-Id' => 'actor',
        ])->assertOk();
        $this->assertCount(10, $this->events);
        foreach ($this->events as $event) {
            $expected = match ($event['event']) {
                'explicit' => '203.0.113.10',
                'redacted', 'explicit collector' => null,
                default => '198.51.100.23',
            };
            $this->assertArrayHasKey('$ip', $event['properties']);
            $this->assertSame($expected, $event['properties']['$ip']);
            $this->assertTrue($event['properties']['$geoip_disable']);
        }
        $this->assertSame([], $this->app->make(Context::class)->data());
    }

    public function testExplicitContextIpIncludingNullIsNotReplaced(): void
    {
        $this->request();
        $context = $this->app->make(Context::class);
        foreach (['203.0.113.10', null] as $ip) {
            $context->with(['properties' => ['$ip' => $ip]], function () use ($context, $ip) {
                $this->assertSame($ip, $context->data()['properties']['$ip']);
                PostHog::capture('scoped');
            });
        }
        $this->assertSame(['203.0.113.10', null], array_column(array_column($this->events, 'properties'), '$ip'));
        $this->assertSame('198.51.100.23', $context->data()['properties']['$ip'] ?? null);
    }

    #[DataProvider('disabledConfigurations')]
    public function testDisabledIpCollectionDoesNotReadTheRequest(array $settings): void
    {
        foreach ($settings as $key => $value) {
            $this->app['config']->set('posthog.' . $key, $value);
        }
        $request = new class extends Request {
            public int $ipCalls = 0;

            public function ip()
            {
                $this->ipCalls++;
                return '198.51.100.23';
            }
        };
        $context = $this->app->make(Context::class);
        $context->fromRequest($request);
        $this->assertArrayNotHasKey('$ip', $context->data()['properties']);
        $this->assertSame(0, $request->ipCalls);
    }

    public static function disabledConfigurations(): array
    {
        return [
            'IP opt-out' => [['capture_ip' => false]],
            'SDK disabled' => [['enabled' => false]],
            'missing project key' => [['api_key' => '   ']],
        ];
    }

    public function testUnavailableOrFailingIpLookupDoesNotBreakCapture(): void
    {
        $request = new class extends Request {
            public bool $fail = false;

            public function ip()
            {
                if ($this->fail) {
                    throw new RuntimeException('private IP lookup details');
                }
                return null;
            }
        };
        $this->app->make(Context::class)->fromRequest($request);
        PostHog::capture('unavailable');
        $request->fail = true;
        PostHog::capture('failed lookup');
        $this->assertCount(2, $this->events);
        foreach ($this->events as $event) {
            $this->assertArrayNotHasKey('$ip', $event['properties']);
        }
    }

    public function testFakeWithoutAProjectKeyStillCollectsIpUnlessOptedOut(): void
    {
        $this->app['config']->set('posthog.api_key', null);
        $this->app['config']->set('posthog.enabled', false);
        $fake = PostHog::fake();
        $this->request();
        PostHog::capture('fake request');
        $fake->assertCaptured('fake request', fn ($event) => ($event['properties']['$ip'] ?? null) === '198.51.100.23');
        $this->app['config']->set('posthog.capture_ip', false);
        PostHog::capture('opted out');
        $fake->assertCaptured('opted out', fn ($event) => !array_key_exists('$ip', $event['properties']));
    }

    public function testOptOutDoesNotRemoveApplicationProvidedIp(): void
    {
        $this->app['config']->set('posthog.capture_ip', false);
        $this->request();
        PostHog::capture('no automatic IP');
        PostHog::capture('explicit', ['$ip' => '203.0.113.10']);
        $this->assertArrayNotHasKey('$ip', $this->events[0]['properties']);
        $this->assertSame('203.0.113.10', $this->events[1]['properties']['$ip']);
    }

    public function testQueueJobsFreshScopesAndLaterRequestsDoNotInheritRequestIp(): void
    {
        $this->request();
        $payload = QueueContext::payload();
        $this->assertArrayNotHasKey('$ip', $payload['_posthog']['properties']);
        PostHog::withContext([], fn () => PostHog::capture('fresh', [], 'fresh'), ['fresh' => true]);
        $job = $this->createStub(Job::class);
        $job->method('payload')->willReturn($payload);
        $this->app['events']->dispatch(new JobProcessing('sync', $job));
        PostHog::capture('job');
        $this->app['events']->dispatch(new JobProcessed('sync', $job));
        PostHog::capture('request restored');
        $this->request('203.0.113.10', 'next');
        PostHog::capture('next request');
        $this->app->make(Context::class)->end();
        PostHog::capture('console', [], 'console');
        $this->assertArrayNotHasKey('$ip', $this->events[0]['properties']);
        $this->assertArrayNotHasKey('$ip', $this->events[1]['properties']);
        $this->assertSame('198.51.100.23', $this->events[2]['properties']['$ip'] ?? null);
        $this->assertSame('203.0.113.10', $this->events[3]['properties']['$ip'] ?? null);
        $this->assertArrayNotHasKey('$ip', $this->events[4]['properties']);
    }

    public function testDeferredExceptionsKeepTheOriginatingIp(): void
    {
        $this->request();
        $exception = new RuntimeException('origin');
        $reporter = $this->app->make(ExceptionReporter::class);
        $reporter->rememberContext($exception);
        $this->request('203.0.113.10', 'next');
        $reporter->report($exception);
        $this->assertSame('actor', $this->events[0]['distinct_id']);
        $this->assertSame('198.51.100.23', $this->events[0]['properties']['$ip'] ?? null);
        $this->assertSame('203.0.113.10', $this->app->make(Context::class)->data()['properties']['$ip'] ?? null);
    }

    public function testModelSnapshotsRetainOnlyApprovedRequestPropertiesUntilCommit(): void
    {
        $this->app['config']->set('posthog.autocapture.models', [
            'enabled' => true, 'allow' => [CollectedRecord::class => ['state']],
        ]);
        DB::getSchemaBuilder()->create('collected_records', function (Blueprint $table) {
            $table->id();
            $table->string('state');
            $table->string('email');
        });
        $this->request();
        DB::beginTransaction();
        $this->app->make(Context::class)->with(['properties' => ['secret' => 'private']], function () {
            CollectedRecord::create(['state' => 'new', 'email' => 'private']);
        });
        $this->assertSame([], $this->events);
        $this->request('203.0.113.10', 'next');
        DB::commit();
        $this->assertCount(1, $this->events);
        $this->assertSame('198.51.100.23', $this->events[0]['properties']['$ip'] ?? null);
        $this->assertArrayNotHasKey('secret', $this->events[0]['properties']);
        $this->assertArrayNotHasKey('$request_method', $this->events[0]['properties']);
        $this->assertSame('203.0.113.10', $this->app->make(Context::class)->data()['properties']['$ip'] ?? null);
    }
}
