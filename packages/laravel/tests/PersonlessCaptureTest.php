<?php

namespace PostHog\Laravel\Tests;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Orchestra\Testbench\TestCase;
use PostHog\Client;
use PostHog\HttpClient;
use PostHog\Laravel\Context;
use PostHog\Laravel\Facades\PostHog;
use PostHog\Laravel\PostHogServiceProvider;
use PostHog\Laravel\QueueContext;
use PostHog\Laravel\Tests\Fixtures\CollectedRecord;
use PostHog\Laravel\Tests\Fixtures\OrderCompleted;
use RuntimeException;

class PersonlessCaptureTest extends TestCase
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

    private function assertPersonless(array $events): void
    {
        $this->assertNotEmpty($events);
        foreach ($events as $event) {
            $this->assertTrue(Str::isUuid($event['distinct_id'], version: 4));
            $this->assertFalse($event['properties']['$process_person_profile']);
        }
        $this->assertCount(count($events), array_unique(array_column($events, 'distinct_id')));
    }

    public function testManualCapturesAndExceptionsArePersonlessWithoutCreatingFlagIdentity(): void
    {
        $http = $this->createMock(HttpClient::class);
        $http->expects($this->never())->method('sendRequest');
        $sdk = new Client('project-key', [
            'consumer' => 'noop',
            'before_send' => $this->app['config']->get('posthog.sdk.before_send'),
        ], $http, loadFeatureFlags: false);
        $manager = $this->app->make(\PostHog\Laravel\PostHog::class);
        (new \ReflectionProperty(\PostHog\Laravel\PostHog::class, 'client'))->setValue($manager, $sdk);
        $context = $this->app->make(Context::class);
        $context->fromRequest(Request::create('/personless', server: [
            'HTTP_X_POSTHOG_SESSION_ID' => 'frontend-session',
        ]));
        PostHog::capture('first');
        PostHog::capture('second');
        PostHog::captureException(new RuntimeException('handled'));
        $this->assertCount(3, $this->events);
        $this->assertPersonless($this->events);
        $this->assertSame(['frontend-session'], array_unique(array_column(
            array_column($this->events, 'properties'),
            '$session_id',
        )));
        $this->assertNull($context->data()['distinctId'] ?? null);
        $this->assertArrayNotHasKey('distinctId', QueueContext::payload()['_posthog']);
        $this->assertSame([], PostHog::evaluateFlags()->getKeys());
        $this->assertCount(3, $this->events);
        PostHog::capture('explicit profile override', ['$process_person_profile' => true]);
        $this->assertTrue($this->events[3]['properties']['$process_person_profile']);
    }

    public function testAnonymousRequestsCollectEventsWithoutSessionsOrVisitorPersistence(): void
    {
        foreach (['requests', 'pageviews', 'auth'] as $section) {
            $this->app['config']->set('posthog.autocapture.' . $section . '.enabled', true);
        }
        $this->app['config']->set('posthog.autocapture.auth.events', ['failed']);
        $this->app['config']->set('app.url', 'https://app.example');
        Route::get('/personless', function () {
            PostHog::capture('manual');
            $this->app['events']->dispatch(new Failed('web', null, ['email' => 'private@example.test']));
            $this->app['events']->dispatch(new OrderCompleted());
            $this->assertArrayNotHasKey('distinctId', QueueContext::payload()['_posthog']);
            return response('<p>ok</p>')->header('Content-Type', 'text/html');
        });
        for ($i = 0; $i < 2; $i++) {
            $response = $this->get('/personless', ['X-PostHog-Session-Id' => 'frontend-session']);
            $response->assertOk();
            $this->assertFalse($response->headers->has('Set-Cookie'));
        }
        $this->assertCount(10, $this->events);
        $this->assertPersonless($this->events);
        $this->assertSame([
            'manual' => 2, 'laravel_auth_failed' => 2, 'order_completed' => 2,
            'laravel_request' => 2, '$pageview' => 2,
        ], array_count_values(array_column($this->events, 'event')));
        foreach ($this->events as $event) {
            $this->assertSame('frontend-session', $event['properties']['$session_id']);
        }
        $this->assertStringNotContainsString('private@example.test', json_encode($this->events));
        $this->assertSame([], $this->app->make(Context::class)->data());
    }

    public function testExplicitContextAuthenticationAndFrontendIdentityStillWin(): void
    {
        $context = $this->app->make(Context::class);
        $request = Request::create('/', server: ['HTTP_X_POSTHOG_DISTINCT_ID' => 'frontend']);
        $context->fromRequest($request);
        PostHog::capture('frontend');
        $request->setUserResolver(fn () => new GenericUser(['id' => 'authenticated']));
        PostHog::capture('authenticated');
        PostHog::withContext(['distinctId' => 'scoped'], fn () => PostHog::capture('scoped'));
        PostHog::capture('explicit', [], 'explicit');
        $this->assertSame(['frontend', 'authenticated', 'scoped', 'explicit'], array_column($this->events, 'distinct_id'));
        foreach ($this->events as $event) {
            $this->assertArrayNotHasKey('$process_person_profile', $event['properties']);
        }
    }

    public function testAnonymousModelEventsRespectTransactionsAndDoNotBorrowLaterIdentity(): void
    {
        $resolvedId = null;
        $this->app->instance('changing-identity', function () use (&$resolvedId) {
            return $resolvedId;
        });
        $this->app['config']->set('posthog.distinct_id', 'changing-identity');
        $this->app['config']->set('posthog.autocapture.models', [
            'enabled' => true, 'allow' => [CollectedRecord::class => ['state']],
        ]);
        DB::getSchemaBuilder()->create('collected_records', function (Blueprint $table) {
            $table->id();
            $table->string('state');
            $table->string('email');
        });
        $context = $this->app->make(Context::class);
        $context->begin(['sessionId' => 'origin-session', 'groups' => ['team' => 'original']]);
        DB::beginTransaction();
        $model = CollectedRecord::create(['state' => 'created', 'email' => 'private']);
        $model->update(['state' => 'updated']);
        DB::beginTransaction();
        CollectedRecord::create(['state' => 'rolled back', 'email' => 'private']);
        DB::rollBack();
        $this->assertSame([], $this->events);
        $resolvedId = 'later-user';
        $context->begin(['distinctId' => 'later-user', 'sessionId' => 'later-session']);
        DB::commit();
        $this->assertCount(2, $this->events);
        $this->assertPersonless($this->events);
        $this->assertSame(['created', 'updated'], array_column(array_column(
            array_column($this->events, 'properties'), 'attributes',
        ), 'state'));
        foreach ($this->events as $event) {
            $this->assertSame('origin-session', $event['properties']['$session_id']);
            $this->assertSame(['team' => 'original'], $event['properties']['$groups']);
        }
        $this->assertSame('later-user', $context->data()['distinctId']);
        $resolvedId = null;
        $context->begin();
        CollectedRecord::create(['state' => 'immediate', 'email' => 'private']);
        $this->assertCount(3, $this->events);
        $this->assertPersonless($this->events);
    }

    public function testJobOutcomesWithoutIdentityArePersonless(): void
    {
        $this->app['config']->set('posthog.autocapture.jobs.enabled', true);
        $job = $this->createStub(Job::class);
        $job->method('payload')->willReturn([]);
        $job->method('resolveQueuedJobClass')->willReturn('App\Jobs\Import');
        $job->method('attempts')->willReturn(1);
        $this->app['events']->dispatch(new JobProcessing('sync', $job));
        $this->app['events']->dispatch(new JobProcessed('sync', $job));
        $this->assertCount(1, $this->events);
        $this->assertSame('laravel_job', $this->events[0]['event']);
        $this->assertPersonless($this->events);
        $this->assertSame([], $this->app->make(Context::class)->data());
    }
}
