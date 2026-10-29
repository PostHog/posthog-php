<?php

namespace PostHog\Laravel\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use PostHog\Laravel\Autocapture\RequestSummary;
use PostHog\Laravel\AutocaptureServiceProvider;
use PostHog\Laravel\Context;
use PostHog\Laravel\Facades\PostHog;
use PostHog\Laravel\PostHogServiceProvider;
use PostHog\Laravel\Tests\Fixtures\CollectedRecord;
use PostHog\Laravel\Tests\Fixtures\OrderCompleted;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class AutocaptureTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        // The main provider discovers the separate optional provider, exactly once.
        return [PostHogServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('posthog.api_key', 'project-key');
        $app['config']->set('posthog.sdk.consumer', 'noop');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:']);
        $app['config']->set('posthog.autocapture.domain_events', [OrderCompleted::class => [
            'event' => 'order_completed',
            'properties' => [
                'amount' => 'amount', 'currency' => 'details.currency', 'unsafe' => 'model', 'absent' => 'absent',
            ],
        ]]);
    }

    private function identity(string $id = 'actor'): void
    {
        $this->app->make(Context::class)->begin(['distinctId' => $id, 'groups' => ['team' => $id]]);
    }

    private function enable(string $section): void
    {
        $this->app['config']->set('posthog.autocapture.' . $section . '.enabled', true);
    }

    private function events(): array
    {
        return array_column(PostHog::getFacadeRoot()->recorded('capture'), 'message');
    }

    private function modelTable(): void
    {
        Schema::create('collected_records', function (Blueprint $table) {
            $table->id();
            $table->string('state');
            $table->string('email');
        });
    }

    public function testDefaultCollectorsAreOffAndProviderRegistrationIsIdempotent(): void
    {
        $fake = PostHog::fake();
        $this->app->register(AutocaptureServiceProvider::class);
        $this->identity();
        Route::get('/off', fn () => response('ok')->header('Content-Type', 'text/html'));
        $this->get('/off', ['X-PostHog-Distinct-Id' => 'actor'])->assertOk();
        $this->app['events']->dispatch(new \Illuminate\Auth\Events\Login(
            'web',
            new GenericUser(['id' => 'actor']),
            false,
        ));
        $this->modelTable();
        CollectedRecord::create(['state' => 'new', 'email' => 'secret@example.test']);
        $input = new ArrayInput([]);
        $this->app['events']->dispatch(new CommandStarting('safe', $input, new NullOutput()));
        $this->app['events']->dispatch(new CommandFinished('safe', $input, new NullOutput(), 0));
        $fake->assertNothingCaptured();
        $this->assertSame('off', trim(Blade::render("@posthogFeature('flag')\n on\n@else\n off\n@endposthogFeature")));
    }

    public function testPublishedCollectorConfigAndNestedOverridesAreHonored(): void
    {
        $path = $this->app->configPath('posthog-autocapture.php');
        $original = is_file($path) ? file_get_contents($path) : null;
        @unlink($path);
        try {
            $this->artisan('vendor:publish', ['--tag' => 'posthog-autocapture-config'])->assertSuccessful();
            $this->assertFileExists($path);
            $defaults = require $path;
            $this->assertFalse($defaults['requests']['enabled']);
            $this->assertSame([], $defaults['domain_events']);
            $config = $this->app['config'];
            $config->set('posthog.autocapture', []);
            $config->set('posthog-autocapture.requests.enabled', true);
            (new AutocaptureServiceProvider($this->app))->register();
            $this->assertTrue($config->get('posthog.autocapture.requests.enabled'));
            $config->set('posthog.autocapture.requests', ['enabled' => false]);
            (new AutocaptureServiceProvider($this->app))->register();
            $this->assertFalse($config->get('posthog.autocapture.requests.enabled'));
        } finally {
            if ($original === null) {
                @unlink($path);
            } else {
                file_put_contents($path, $original);
            }
        }
    }

    public function testRequestSummaryAndPageviewUseTemplatesAndConfiguredOriginOnly(): void
    {
        $fake = PostHog::fake();
        $this->enable('requests');
        $this->enable('pageviews');
        $this->app->register(AutocaptureServiceProvider::class);
        $this->app['config']->set('app.url', 'https://user:secret@app.example:8443/base?secret=hidden');
        Route::get('/orders/{order}', fn () => response('<p>secret</p>')->header('Content-Type', 'text/html'));
        foreach (['first-private', 'second-private'] as $id) {
            $this->get('/orders/' . $id . '?token=secret', ['X-PostHog-Distinct-Id' => $id])->assertOk();
        }
        $fake->assertCapturedCount(4);
        $messages = $this->events();
        $this->assertSame(
            ['first-private', 'first-private', 'second-private', 'second-private'],
            array_column($messages, 'distinctId'),
        );
        $this->assertSame(['route', 'method', 'status_code', 'duration_ms'], array_keys($messages[0]['properties']));
        $this->assertSame('/orders/{order}', $messages[0]['properties']['route']);
        $this->assertSame('https://app.example:8443/orders/{order}', $messages[1]['properties']['$current_url']);
        $this->assertSame('/orders/{order}', $messages[1]['properties']['$pathname']);
        $this->assertGreaterThanOrEqual(0, $messages[0]['properties']['duration_ms']);
        $this->assertStringNotContainsString('secret', json_encode(array_column($messages, 'properties')));
        $this->assertStringNotContainsString('private', json_encode(array_column($messages, 'properties')));
        $this->assertSame([], $this->app->make(Context::class)->snapshot());
    }

    public function testHealthAssetsUnmatchedAndSamplingDoNotCollectAndPageviewIsSeparate(): void
    {
        $fake = PostHog::fake();
        $this->enable('requests');
        $this->enable('pageviews');
        foreach (['/up', '/health/check', '/assets/{file}', '/bundle.js'] as $uri) {
            Route::get($uri, fn () => response('ok')->header('Content-Type', 'text/html'));
        }
        foreach (['/up', '/health/check', '/assets/private', '/bundle.js', '/unmatched'] as $uri) {
            $this->get($uri, ['X-PostHog-Distinct-Id' => 'actor']);
        }
        $fake->assertNothingCaptured();
        Route::get('/json', fn () => response()->json(['ok' => true]));
        Route::post('/html', fn () => response('ok')->header('Content-Type', 'text/html'));
        Route::get('/redirect', fn () => redirect('/json'));
        Route::get('/error', fn () => response('error', 503)->header('Content-Type', 'text/html'));
        $this->get('/json', ['X-PostHog-Distinct-Id' => 'actor']);
        $this->post('/html', [], ['X-PostHog-Distinct-Id' => 'actor']);
        $this->get('/redirect', ['X-PostHog-Distinct-Id' => 'actor']);
        $this->get('/error', ['X-PostHog-Distinct-Id' => 'actor']);
        $fake->assertCapturedCount(4);
        $fake->assertNotCaptured('$pageview');
        $this->app['config']->set('posthog.autocapture.requests.sample_rate', 0);
        $this->get('/json', ['X-PostHog-Distinct-Id' => 'actor']);
        $fake->assertCapturedCount(4);
    }

    public function testPageviewCanBeEnabledWithoutRequestSummary(): void
    {
        $fake = PostHog::fake();
        $this->enable('pageviews');
        Route::get('/page', fn () => response('ok')->header('Content-Type', 'text/html'));
        $this->get('/page', ['X-PostHog-Distinct-Id' => 'actor']);
        $fake->assertCapturedCount(1);
        $fake->assertCaptured('$pageview');
        $fake->assertNotCaptured('laravel_request');
    }

    public function testAuthUsesOnlyIdentifierAndGuardAndFailedLockoutNeedExplicitOptIn(): void
    {
        $fake = PostHog::fake();
        $this->enable('auth');
        $this->identity('anonymous');
        $user = new GenericUser(['id' => 123, 'email' => 'secret@example.test', 'password' => 'secret']);
        $events = $this->app['events'];
        $events->dispatch(new \Illuminate\Auth\Events\Registered($user));
        $events->dispatch(new \Illuminate\Auth\Events\Login('web', $user, true));
        $events->dispatch(new \Illuminate\Auth\Events\Logout('web', $user));
        $failed = new \Illuminate\Auth\Events\Failed(
            'web',
            $user,
            ['email' => 'secret@example.test', 'password' => 'secret'],
        );
        $lockout = new \Illuminate\Auth\Events\Lockout(Request::create('/?password=secret'));
        $events->dispatch($failed);
        $events->dispatch($lockout);
        $fake->assertCapturedCount(3);
        $this->app['config']->set('posthog.autocapture.auth.events', ['failed', 'lockout']);
        $events->dispatch($failed);
        $events->dispatch($lockout);
        $this->assertSame(['123', '123', '123', 'anonymous', 'anonymous'], array_column($this->events(), 'distinctId'));
        $this->assertSame(
            [[], ['guard' => 'web'], ['guard' => 'web'], ['guard' => 'web'], []],
            array_column($this->events(), 'properties'),
        );
        $this->assertStringNotContainsString('secret', json_encode($this->events()));
    }

    public function testDomainMapsOnlyExactClassAndPublicJsonSafeProperties(): void
    {
        $fake = PostHog::fake();
        $this->identity();
        $this->app['events']->dispatch(new OrderCompleted(model: new GenericUser(['email' => 'secret'])));
        $this->assertSame(['amount' => 42, 'currency' => 'USD'], $this->events()[0]['properties']);
        $this->app['events']->dispatch(new class extends OrderCompleted {
        });
        $fake->assertCapturedCount(1);
    }

    public function testModelsCaptureAfterCommitWithOriginalIdentityNoPayloadAndRollbackDrops(): void
    {
        $fake = PostHog::fake();
        $this->modelTable();
        $this->enable('models');
        $this->app['config']->set('posthog.autocapture.models.allow', [CollectedRecord::class => ['state']]);
        $this->identity('original');
        DB::beginTransaction();
        $record = CollectedRecord::create(['state' => 'created', 'email' => 'secret@example.test']);
        $record->update(['state' => 'updated']);
        $record->delete();
        $fake->assertNothingCaptured();
        $this->identity('next');
        DB::commit();
        $fake->assertCapturedCount(3);
        $this->assertSame(['original', 'original', 'original'], array_column($this->events(), 'distinctId'));
        $this->assertSame(
            ['created', 'updated', 'updated'],
            array_map(fn ($event) => $event['properties']['attributes']['state'], $this->events()),
        );
        $this->assertSame(['team' => 'original'], $this->events()[0]['groups']);
        $this->assertStringNotContainsString('secret', json_encode($this->events()));
        $this->assertSame('next', $this->app->make(Context::class)->data()['distinctId']);
        $exception = new RuntimeException('original failure');
        try {
            DB::transaction(function () use ($exception) {
                CollectedRecord::create(['state' => 'rolled back', 'email' => 'secret']);
                throw $exception;
            });
            $this->fail('Transaction must throw');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }
        $fake->assertCapturedCount(3);
        $this->assertSame(0, CollectedRecord::count());
        $this->app['config']->set('posthog.autocapture.models.allow', [CollectedRecord::class => []]);
        CollectedRecord::create(['state' => 'safe', 'email' => 'secret']);
        $this->assertSame(['model' => CollectedRecord::class, 'attributes' => []], $this->events()[3]['properties']);
    }

    public function testNestedRollbackDropsOnlyRolledBackModelEventsAndUnallowlistedModelsDoNotCollect(): void
    {
        $fake = PostHog::fake();
        $this->modelTable();
        $this->enable('models');
        $this->identity();
        CollectedRecord::create(['state' => 'not allowed', 'email' => 'secret']);
        $fake->assertNothingCaptured();
        $this->app['config']->set('posthog.autocapture.models.allow', [CollectedRecord::class => []]);
        DB::beginTransaction();
        CollectedRecord::create(['state' => 'outer', 'email' => 'secret']);
        DB::beginTransaction();
        CollectedRecord::create(['state' => 'inner', 'email' => 'secret']);
        DB::rollBack();
        $fake->assertNothingCaptured();
        DB::commit();
        $fake->assertCapturedCount(1);
    }

    public function testQueueOutcomesAreOncePerAttemptAndNeverContainJobPayloadOrException(): void
    {
        $fake = PostHog::fake();
        $this->enable('jobs');
        $job = $this->createStub(\Illuminate\Contracts\Queue\Job::class);
        $job->method('resolveQueuedJobClass')->willReturn('App\\Jobs\\Import');
        $job->method('resolveName')->willReturn('secret display name');
        $job->method('attempts')->willReturn(2);
        $events = $this->app['events'];
        foreach (['first', 'second'] as $id) {
            $events->dispatch(new JobProcessing('sync', $job));
            $this->identity($id);
            $event = $id === 'first' ? new JobProcessed('sync', $job)
                : new JobExceptionOccurred('sync', $job, new RuntimeException('secret failure'));
            $events->dispatch($event);
            $events->dispatch($event);
        }
        $fake->assertCapturedCount(2);
        $this->assertSame(['first', 'second'], array_column($this->events(), 'distinctId'));
        $this->assertSame(
            ['success', 'failed'],
            array_map(fn ($event) => $event['properties']['outcome'], $this->events()),
        );
        $this->assertSame(['name', 'outcome', 'attempts', 'duration_ms'], array_keys($this->events()[0]['properties']));
        $this->assertStringNotContainsString('secret', json_encode($this->events()));
        $this->assertSame([], $this->app->make(Context::class)->data());
    }

    public function testRealSyncJobSuccessAndFailureKeepOriginalExceptionAndOuterWorkerContext(): void
    {
        $fake = PostHog::fake();
        $this->enable('jobs');
        $this->identity('outer');
        \Illuminate\Support\Facades\Bus::dispatchSync(new Fixtures\CaptureJob('job-user'));
        try {
            \Illuminate\Support\Facades\Bus::dispatchSync(new Fixtures\FailingCaptureJob('failed-user'));
            $this->fail('Job must fail');
        } catch (RuntimeException $exception) {
            $this->assertSame('failed job', $exception->getMessage());
        }
        $fake->assertCaptured('laravel_job', fn ($event) => $event['distinctId'] === 'job-user'
            && $event['properties']['name'] === Fixtures\CaptureJob::class
            && $event['properties']['outcome'] === 'success');
        $fake->assertCaptured('laravel_job', fn ($event) => $event['distinctId'] === 'failed-user'
            && $event['properties']['name'] === Fixtures\FailingCaptureJob::class
            && $event['properties']['outcome'] === 'failed');
        $this->assertSame('outer', $this->app->make(Context::class)->data()['distinctId']);
    }

    public function testCommandAndSchedulerMetadataExcludeArgumentsAndDeduplicateFailure(): void
    {
        $fake = PostHog::fake();
        $this->enable('commands');
        $this->enable('scheduler');
        $input = new ArrayInput(['secret' => 'password']);
        $output = new NullOutput();
        $events = $this->app['events'];
        $events->dispatch(new CommandStarting('import --secret=password', $input, $output));
        $this->identity();
        $events->dispatch(new CommandFinished('import --secret=password', $input, $output, 7));
        $this->identity();
        $task = new Event($this->createStub(EventMutex::class), 'php artisan import --secret=password');
        $task->exitCode = 1;
        $events->dispatch(new ScheduledTaskStarting($task));
        $events->dispatch(new ScheduledTaskFinished($task, 0.1));
        $events->dispatch(new ScheduledTaskFailed($task, new RuntimeException('secret'), 0.1));
        $task->runInBackground = true;
        $task->exitCode = null;
        $events->dispatch(new ScheduledTaskStarting($task));
        $events->dispatch(new ScheduledTaskFinished($task, 0.1));
        $fake->assertCapturedCount(3);
        $this->assertSame('import', $this->events()[0]['properties']['name']);
        $this->assertSame('failed', $this->events()[1]['properties']['outcome']);
        $this->assertSame('background_started', $this->events()[2]['properties']['outcome']);
        $this->assertStringNotContainsString('password', json_encode($this->events()));
        $this->assertStringNotContainsString('secret', json_encode($this->events()));
    }

    public function testBladeUsesSharedFlagSnapshotsAndCoreExposureDeduplicationAcrossScopes(): void
    {
        $this->enable('blade');
        $http = $this->createMock(\PostHog\HttpClient::class);
        $http->expects($this->exactly(2))->method('sendRequest')->willReturn(new \PostHog\HttpResponse(
            json_encode(['flags' => ['checkout' => ['enabled' => true, 'variant' => null]]]),
            200,
        ));
        $captures = [];
        $sdk = new \PostHog\Client('project-key', [
            'consumer' => 'noop',
            'before_send' => static function ($event) use (&$captures) {
                $captures[] = $event;
                return $event;
            },
        ], $http, loadFeatureFlags: false);
        $manager = $this->app->make(\PostHog\Laravel\PostHog::class);
        (new \ReflectionProperty(\PostHog\Laravel\PostHog::class, 'sdk'))->setValue($manager, $sdk);
        foreach (['first', 'second'] as $id) {
            $this->identity($id);
            $snapshot = $manager->evaluateFlags();
            \PostHog\Laravel\Autocapture\Collector::capture('between reads', []);
            $this->assertSame($snapshot, $manager->evaluateFlags());
            for ($i = 0; $i < 2; $i++) {
                $template = "@posthogFeature('checkout')\n on\n@else\n off\n@endposthogFeature";
                $this->assertSame('on', trim(Blade::render($template)));
            }
        }
        $exposures = array_values(array_filter($captures, fn ($event) => $event['event'] === '$feature_flag_called'));
        $this->assertCount(2, $exposures);
        $this->assertSame(['first', 'second'], array_column($exposures, 'distinct_id'));
    }

    public function testOutcomeExclusionsAndNoIdentityDoNotInventAnonymousEvents(): void
    {
        $fake = PostHog::fake();
        $this->enable('jobs');
        $this->app['config']->set('posthog.autocapture.jobs.exclude', ['App\\Jobs\\*']);
        $job = $this->createStub(\Illuminate\Contracts\Queue\Job::class);
        $job->method('resolveQueuedJobClass')->willReturn('App\\Jobs\\Import');
        $job->method('attempts')->willReturn(1);
        $this->app['events']->dispatch(new JobProcessing('sync', $job));
        $this->identity();
        $this->app['events']->dispatch(new JobProcessed('sync', $job));
        $this->app['events']->dispatch(new OrderCompleted());
        $fake->assertNothingCaptured();
    }

    public function testCollectorFailureNeverMasksOriginalExceptionOrLeaksContextProperties(): void
    {
        $this->enable('requests');
        $this->app->instance(\PostHog\Laravel\PostHog::class, new class ($this->app) extends \PostHog\Laravel\PostHog {
            public function capture(
                string|array $event,
                array $properties = [],
                ?string $distinctId = null,
                array $groups = [],
            ): bool {
                throw new RuntimeException('collector failed');
            }
        });
        $this->identity();
        $request = Request::create('/test?secret=password');
        $route = new \Illuminate\Routing\Route('GET', 'test', fn () => null);
        $request->setRouteResolver(fn () => $route);
        $exception = new RuntimeException('original');
        try {
            (new RequestSummary())->handle($request, fn () => throw $exception);
            $this->fail('Original exception must be thrown');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }
        $fake = PostHog::fake();
        $this->app->make(Context::class)->begin(['distinctId' => 'actor', 'properties' => ['password' => 'secret']]);
        $this->app['events']->dispatch(new OrderCompleted());
        $this->assertStringNotContainsString('secret', json_encode($this->events()));
        $this->assertSame(['password' => 'secret'], $this->app->make(Context::class)->data()['properties']);
        $fake->assertCapturedCount(1);
    }
}
