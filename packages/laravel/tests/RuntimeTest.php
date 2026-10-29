<?php

namespace PostHog\Laravel\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use PostHog\Client;
use PostHog\HttpClient;
use PostHog\HttpResponse;
use PostHog\Laravel\Context;
use PostHog\Laravel\ExceptionReporter;
use PostHog\Laravel\Facades\PostHog as Facade;
use PostHog\Laravel\FlagDefinitionCacheProvider;
use PostHog\Laravel\Middleware\RequestContext;
use PostHog\Laravel\PostHog;
use PostHog\Laravel\PostHogServiceProvider;
use PostHog\Laravel\Tests\Fixtures\IgnoredFailure;
use PostHog\Laravel\Tests\Fixtures\CaptureJob;
use PostHog\Laravel\Tests\Fixtures\FailingCaptureJob;
use PostHog\Laravel\Tests\Fixtures\FlushClient;
use PostHog\Laravel\Tests\Fixtures\IdentityResolver;
use PostHog\Laravel\Tests\Fixtures\GroupsResolver;
use PostHog\Laravel\Tests\Fixtures\ScopedFailingJob;
use PostHog\Laravel\Tests\Fixtures\CaptureCallback;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class RuntimeTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PostHogServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('posthog.api_key', 'project-key');
        $app['config']->set('posthog.sdk.consumer', 'noop');
        $app['config']->set('queue.default', 'sync');
    }

    public function testDiscoveryBindingsAndLazyDisabledClient(): void
    {
        $manager = $this->app->make(PostHog::class);
        $this->assertSame($manager, $this->app->make('posthog'));
        $sdk = new \ReflectionProperty(PostHog::class, 'sdk');
        $this->assertNull($sdk->getValue($manager));
        $this->app['config']->set('posthog.api_key', '   ');
        $this->assertFalse($manager->capture('no traffic'));
        $this->assertNull($sdk->getValue($manager));
        $this->assertSame([], $manager->evaluateFlags('user')->getKeys());
        $this->assertFalse($manager->captureException(new RuntimeException('disabled')));
        $this->assertSame([], $manager->client()->evaluateFlags('user')->getKeys());
        $this->assertFalse($manager->client()->capture(['event' => 'disabled', 'distinctId' => 'user']));
    }

    public function testDisabledWithKeyAndSecretDoesNotFetch(): void
    {
        $this->app['config']->set('posthog.enabled', false);
        $this->app['config']->set('posthog.secret_key', 'server-secret');
        $manager = $this->app->make(PostHog::class);
        $this->assertSame([], $manager->evaluateFlags('user')->getKeys());
        $this->assertFalse($manager->client()->captureException('disabled'));
        $this->assertFalse($manager->enabled());
    }

    public function testActualHttpRequestsResolveAuthAfterMiddlewareAndNeverLeak(): void
    {
        $fake = Facade::fake();
        Route::get('/runtime/{id}', function ($id) {
            $this->app['auth']->setUser(new GenericUser(['id' => $id, 'email' => 'private@example.test']));
            Facade::capture('request');
            Facade::capture('explicit', [], 'override');
            return 'ok';
        });
        foreach (['first', 'second'] as $id) {
            $this->get('/runtime/' . $id . '?secret=private', [
                'X-PostHog-Distinct-Id' => 'untrusted',
                'X-PostHog-Session-Id' => 'session-' . $id,
            ])->assertOk();
            $this->app['auth']->forgetGuards();
        }
        $messages = array_column($fake->recorded('capture'), 'message');
        $this->assertSame(['first', 'override', 'second', 'override'], array_column($messages, 'distinctId'));
        $this->assertSame([
            '$session_id' => 'session-first', '$request_method' => 'GET', '$request_path' => '/runtime/{id}',
        ], $messages[0]['properties']);
        $this->assertSame([
            '$session_id' => 'session-second', '$request_method' => 'GET', '$request_path' => '/runtime/{id}',
        ], $messages[2]['properties']);
        $this->assertSame([], $this->app->make(Context::class)->snapshot());
        $this->assertStringNotContainsString('private', json_encode($messages));
    }

    public function testExplicitScopedIdentityWinsOverAuthenticationAndCustomResolvers(): void
    {
        $fake = Facade::fake();
        $context = $this->app->make(Context::class);
        $request = Request::create('/', server: ['HTTP_X_POSTHOG_DISTINCT_ID' => 'header-user']);
        $request->setUserResolver(fn () => new GenericUser(['id' => 'authenticated-user']));
        $context->fromRequest($request);
        $context->with(['distinctId' => 'scoped-user'], fn () => Facade::capture('scoped'));
        $fake->assertCaptured('scoped', fn ($event) => $event['distinctId'] === 'scoped-user');
        $this->assertSame('authenticated-user', $context->data()['distinctId']);
        $this->app['config']->set('posthog.distinct_id', IdentityResolver::class);
        $context->begin(['distinctId' => 'explicit-job-user']);
        $this->assertSame('explicit-job-user', $context->data()['distinctId']);
    }

    public function testNestedArtisanCommandRestoresCallerContextAndFlagSnapshot(): void
    {
        $context = $this->app->make(Context::class);
        $context->begin(['distinctId' => 'caller', 'sessionId' => 'session', 'groups' => ['team' => 'one']]);
        $before = $context->snapshot();
        $flags = new \PostHog\FeatureFlagEvaluations('caller', [], [], Facade::fake()->client());
        $context->flags('caller-snapshot', fn () => $flags);
        $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->rerouteSymfonyCommandEvents();
        \Illuminate\Support\Facades\Artisan::call('list', ['--raw' => true]);
        $this->assertSame($before, $context->snapshot());
        $this->assertSame($flags, $context->flags(
            'caller-snapshot',
            fn () => throw new RuntimeException('lost flags'),
        ));
    }

    public function testHeaderFallbackAndNestedContextRestoreOnFailure(): void
    {
        $context = $this->app->make(Context::class);
        $context->fromRequest(Request::create('/', server: ['HTTP_X_POSTHOG_DISTINCT_ID' => 'anonymous']));
        $this->assertSame('anonymous', $context->data()['distinctId']);
        try {
            $context->with(['groups' => ['team' => 'one']], function () use ($context) {
                $this->assertSame(['team' => 'one'], $context->data()['groups']);
                throw new RuntimeException('restore');
            });
        } catch (RuntimeException) {
        }
        $this->assertArrayNotHasKey('groups', $context->data());
        $context->end();
        $this->assertSame([], $context->data());
    }

    public function testRequestFailureSnapshotsSurviveCleanupAndDontReportIsRespected(): void
    {
        $fake = Facade::fake();
        $middleware = $this->app->make(RequestContext::class);
        $exception = new RuntimeException('failure');
        try {
            $middleware->handle(
                Request::create('/', server: ['HTTP_X_POSTHOG_DISTINCT_ID' => 'origin']),
                fn () => throw $exception,
            );
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }
        $this->app->make(Context::class)->begin(['distinctId' => 'next-request']);
        $handler = $this->app->make(ExceptionHandler::class);
        $handler->report($exception);
        $handler->report($exception);
        $this->assertCount(1, $fake->recorded('captureException'));
        $fake->assertExceptionCaptured(
            fn ($message) => $message['distinctId'] === 'origin'
                && $message['options'] === ['handled' => false, 'mechanism' => 'middleware']
        );
        $handler->dontReport([IgnoredFailure::class]);
        $handler->report(new IgnoredFailure('ignored'));
        $this->assertCount(1, $fake->recorded('captureException'));
        $handler->report(new RuntimeException('manual report'));
        $fake->assertExceptionCaptured(fn ($message) => $message['options']['handled'] === true);
    }

    public function testRealRouteFailuresAreUnhandledButManualReportRemainsHandled(): void
    {
        $fake = Facade::fake();
        Route::get('/failure', fn () => throw new RuntimeException('route failure'));
        Route::get('/manual', function () {
            report(new RuntimeException('manual report'));
            return 'ok';
        });
        $this->get('/failure', ['X-PostHog-Distinct-Id' => 'failed-user'])->assertStatus(500);
        $this->get('/manual', ['X-PostHog-Distinct-Id' => 'manual-user'])->assertOk();
        $fake->assertExceptionCaptured(
            fn ($message) => $message['distinctId'] === 'failed-user'
                && $message['options']['handled'] === false
        );
        $fake->assertExceptionCaptured(
            fn ($message) => $message['distinctId'] === 'manual-user'
                && $message['options']['handled'] === true
        );
    }

    public function testQueueBoundariesCleanTwoJobsAndPreserveFailedJobIdentity(): void
    {
        $fake = Facade::fake();
        Bus::dispatchSync(new CaptureJob('first'));
        Bus::dispatchSync(new CaptureJob('second'));
        $fake->assertCaptured('job', fn ($message) => $message['distinctId'] === 'first');
        $fake->assertCaptured('job', fn ($message) => $message['distinctId'] === 'second');
        $this->assertSame([], $this->app->make(Context::class)->data());
        $job = $this->createStub(\Illuminate\Contracts\Queue\Job::class);
        $this->app['events']->dispatch(new JobProcessing('sync', $job));
        $this->app->make(Context::class)->begin(['distinctId' => 'failed-job']);
        $exception = new RuntimeException('job failure');
        $this->app['events']->dispatch(new JobExceptionOccurred('sync', $job, $exception));
        $this->app->make(Context::class)->begin(['distinctId' => 'later-job']);
        $this->app->make(ExceptionHandler::class)->report($exception);
        $fake->assertExceptionCaptured(
            fn ($message) => $message['distinctId'] === 'failed-job'
                && $message['options'] === ['handled' => false, 'mechanism' => 'task']
        );
        $this->app['events']->dispatch(new JobProcessed('sync', $job));
        $this->assertSame([], $this->app->make(Context::class)->data());
    }

    public function testNestedSyncJobRestoresOuterContextAndActualFailureCleansUp(): void
    {
        $fake = Facade::fake();
        $context = $this->app->make(Context::class);
        $context->begin(['distinctId' => 'outer', 'groups' => ['team' => 'one']]);
        Bus::dispatchSync(new CaptureJob('inner'));
        $this->assertSame('outer', $context->data()['distinctId']);
        $this->assertSame(['team' => 'one'], $context->data()['groups']);
        try {
            Bus::dispatchSync(new FailingCaptureJob('failed-inner'));
            $this->fail('The job must fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('outer', $context->data()['distinctId']);
            $this->app->make(ExceptionHandler::class)->report($exception);
        }
        $fake->assertExceptionCaptured(fn ($message) => $message['distinctId'] === 'failed-inner');
    }

    public function testScopedExceptionDataSurvivesUnwindWithoutInventingAnUnhandledBoundary(): void
    {
        $fake = Facade::fake();
        $context = $this->app->make(Context::class);
        $context->begin(['distinctId' => 'outer']);
        try {
            $context->with(['distinctId' => 'caught-origin'], fn () => throw new RuntimeException('caught'));
        } catch (RuntimeException $exception) {
            $this->assertSame('outer', $context->data()['distinctId']);
            $this->app->make(ExceptionHandler::class)->report($exception);
        }
        $fake->assertExceptionCaptured(
            fn ($message) => $message['distinctId'] === 'caught-origin' && $message['options']['handled'],
        );
        try {
            Bus::dispatchSync(new ScopedFailingJob('job-origin'));
        } catch (RuntimeException $exception) {
            $this->assertSame('outer', $context->data()['distinctId']);
            $this->app->make(ExceptionHandler::class)->report($exception);
        }
        $fake->assertExceptionCaptured(
            fn ($message) => $message['distinctId'] === 'job-origin' && !$message['options']['handled']
                && $message['properties']['$groups'] === ['team' => 'job-team'],
        );
    }

    public function testConsoleAndOctaneBoundariesFlushAndReset(): void
    {
        $client = new FlushClient();
        $manager = $this->app->make(PostHog::class);
        (new \ReflectionProperty(PostHog::class, 'sdk'))->setValue($manager, $client);
        $context = $this->app->make(Context::class);
        $input = new ArrayInput([]);
        $output = new NullOutput();
        $this->app['events']->dispatch(new \Illuminate\Console\Events\CommandStarting('test', $input, $output));
        $context->begin(['distinctId' => 'console']);
        $this->app['events']->dispatch(new \Illuminate\Console\Events\CommandFinished('test', $input, $output, 1));
        $this->assertSame([], $context->data());
        foreach (['first', 'second'] as $id) {
            $this->app['events']->dispatch('Laravel\\Octane\\Events\\RequestReceived', [(object) [
                'request' => Request::create('/', server: ['HTTP_X_POSTHOG_DISTINCT_ID' => $id]),
            ]]);
            $this->assertSame($id, $context->data()['distinctId']);
            $this->app['events']->dispatch('Laravel\\Octane\\Events\\RequestTerminated', [(object) []]);
            $this->assertSame([], $context->data());
        }
        $this->assertSame(3, $client->flushes);
    }

    public function testLifecycleCleanupSurvivesFlushFailure(): void
    {
        $manager = $this->app->make(PostHog::class);
        $client = new FlushClient();
        $client->fail = true;
        (new \ReflectionProperty(PostHog::class, 'sdk'))->setValue($manager, $client);
        $context = $this->app->make(Context::class);
        $context->begin(['distinctId' => 'stale']);
        $this->app['events']->dispatch('Laravel\\Octane\\Events\\TaskTerminated', [(object) []]);
        $this->assertSame([], $context->data());
        $response = $this->app->make(RequestContext::class)->handle(Request::create('/'), fn () => 'ok');
        $this->assertSame('ok', $response);
        $this->assertSame([], $context->data());
        $client->fail = false;
    }

    public function testFlagsAreLazyScopedAndValidNoopStillEvaluates(): void
    {
        $http = $this->createMock(HttpClient::class);
        $http->expects($this->exactly(2))->method('sendRequest')->willReturnCallback(function ($path, $payload) {
            $body = json_decode($payload, true);
            $this->assertContains($body['distinct_id'], ['first', 'second']);
            $this->assertTrue($body['geoip_disable']);
            return new HttpResponse(
                json_encode(['flags' => ['checkout' => ['enabled' => true, 'variant' => null]]]),
                200,
            );
        });
        $sdk = new Client(
            'project-key',
            ['consumer' => 'noop', 'disableGeoip' => true],
            $http,
            loadFeatureFlags: false,
        );
        $manager = $this->app->make(PostHog::class);
        (new \ReflectionProperty(PostHog::class, 'sdk'))->setValue($manager, $sdk);
        $manager->capture('does not fetch', [], 'first');
        $context = $this->app->make(Context::class);
        foreach (['first', 'second'] as $id) {
            $context->begin(['distinctId' => $id]);
            $this->assertSame([], $manager->evaluateFlags(flagKeys: [])->getKeys());
            $flags = $manager->evaluateFlags();
            $this->assertSame($flags, $manager->evaluateFlags());
            $this->assertTrue($flags->isEnabled('checkout'));
            $this->assertTrue($flags->isEnabled('checkout'));
            $context->end();
        }
    }

    public function testNoIdentityNeverLoadsPrivilegedDefinitionsAndResolversUseContainer(): void
    {
        $this->app['config']->set('posthog.secret_key', 'server-secret');
        $http = $this->createMock(HttpClient::class);
        $http->expects($this->never())->method('sendRequest');
        $sdk = new Client(
            'project-key',
            ['consumer' => 'noop'],
            $http,
            loadFeatureFlags: false,
            secretKey: 'server-secret',
        );
        $manager = $this->app->make(PostHog::class);
        (new \ReflectionProperty(PostHog::class, 'sdk'))->setValue($manager, $sdk);
        $this->assertSame([], $manager->evaluateFlags()->getKeys());
        $this->app['config']->set('posthog.distinct_id', IdentityResolver::class);
        $this->app['config']->set('posthog.groups', GroupsResolver::class);
        $context = $this->app->make(Context::class);
        $this->assertSame('resolved-user', $context->data()['distinctId']);
        $this->assertSame(['team' => 'resolved-team'], $context->data()['groups']);
        $context->begin(['groups' => ['team' => 'explicit-team']]);
        $this->assertSame(['team' => 'explicit-team'], $context->data()['groups']);
        $fake = Facade::fake();
        $this->app->make(ExceptionHandler::class)->report(new RuntimeException('grouped error'));
        $fake->assertExceptionCaptured(
            fn ($message) => $message['properties']['$groups'] === ['team' => 'explicit-team']
        );
    }

    public function testLegacyLocalOnlyFlagsLoadDefinitionsLazilyOnFirstIdentifiedUse(): void
    {
        $provider = $this->createMock(\PostHog\FlagDefinitionCacheProvider::class);
        $provider->expects($this->once())->method('getFlagDefinitions')->willReturn([
            'flags' => [[
                'id' => 1,
                'key' => 'local-flag',
                'active' => true,
                'filters' => ['groups' => [['properties' => [], 'rollout_percentage' => 100]]],
            ]],
            'group_type_mapping' => [],
            'cohorts' => [],
        ]);
        $provider->expects($this->never())->method('shouldFetchFlagDefinitions');
        $http = $this->createMock(HttpClient::class);
        $http->expects($this->never())->method('sendRequest');
        $this->app['config']->set('posthog.sdk.flag_definition_cache_provider', $provider);
        $sdk = new Client('project-key', [
            'consumer' => 'noop',
            'flag_definition_cache_provider' => $provider,
        ], $http, loadFeatureFlags: false);
        $manager = $this->app->make(PostHog::class);
        (new \ReflectionProperty(PostHog::class, 'sdk'))->setValue($manager, $sdk);
        $this->assertSame([], $manager->getAllFlags(onlyEvaluateLocally: true));
        $this->app->make(Context::class)->begin(['distinctId' => '0']);
        $this->assertSame(['local-flag' => true], $manager->getAllFlags(onlyEvaluateLocally: true));
        $this->assertSame(['local-flag' => true], $manager->getAllFlags(onlyEvaluateLocally: false));
    }

    public function testCacheProviderRoundTripsCompleteSnapshotAndCoordinatesFetch(): void
    {
        $provider = new FlagDefinitionCacheProvider($this->app['cache']->store('array'), 'isolated', 300, 30);
        $data = [
            'flags' => [],
            'group_type_mapping' => ['0' => 'team'],
            'cohorts' => ['1' => []],
            'property_matching_version' => 2,
            'minimal_flag_called_events' => true,
        ];
        $this->assertNull($provider->getFlagDefinitions());
        $this->assertTrue($provider->shouldFetchFlagDefinitions());
        $this->assertFalse($provider->shouldFetchFlagDefinitions());
        $provider->onFlagDefinitionsReceived($data);
        $this->assertSame($data, $provider->getFlagDefinitions());
        $provider->shutdown();
    }

    public function testContainerCallbackAndFrameworkHandlerOwnership(): void
    {
        $this->app->singleton(CaptureCallback::class);
        $this->app['config']->set('posthog.sdk.before_send', CaptureCallback::class);
        $this->app['config']->set('posthog.sdk.error_tracking.enabled', true);
        $before = set_error_handler(static fn () => false);
        restore_error_handler();
        $manager = $this->app->make(PostHog::class);
        $this->app->make(Context::class)->begin(['distinctId' => 'user']);
        $manager->capture('safe');
        $events = $this->app->make(CaptureCallback::class)->events;
        $this->assertSame('safe', $events[0]['event']);
        $this->assertTrue($events[0]['properties']['$geoip_disable']);
        $after = set_error_handler(static fn () => false);
        restore_error_handler();
        $this->assertSame($before, $after);
    }

    public function testRealClientMetadataGroupsAndCanonicalExceptionAreForwarded(): void
    {
        $this->app->singleton(CaptureCallback::class);
        $this->app['config']->set('posthog.sdk.before_send', CaptureCallback::class);
        $this->app['config']->set('posthog.release_id', 'release-123');
        $manager = $this->app->make(PostHog::class);
        $context = $this->app->make(Context::class);
        $context->begin(['distinctId' => 'user', 'groups' => ['team' => 'one']]);
        $manager->capture(['event' => 'advanced', 'distinctId' => 'override', 'properties' => ['custom' => true]]);
        $manager->captureException(new RuntimeException('canonical'), properties: ['$exception_list' => ['forged']]);
        $events = $this->app->make(CaptureCallback::class)->events;
        $this->assertSame('override', $events[0]['distinct_id']);
        $this->assertSame('posthog-laravel', $events[0]['properties']['$lib']);
        $this->assertSame(PostHog::VERSION, $events[0]['properties']['$lib_version']);
        $this->assertSame('release-123', $events[0]['properties']['$release_id']);
        $this->assertSame(['team' => 'one'], $events[0]['properties']['$groups']);
        $this->assertSame(['team' => 'one'], $events[1]['properties']['$groups']);
        $this->assertSame('RuntimeException', $events[1]['properties']['$exception_list'][0]['type']);
        $this->assertTrue($events[1]['properties']['$exception_list'][0]['mechanism']['handled']);
        $this->assertNull($manager->client()->getContext());
        $exception = new RuntimeException('originating boundary');
        $this->app->make(ExceptionReporter::class)->remember($exception, 'task');
        $context->begin(['distinctId' => 'next-user', 'groups' => ['team' => 'two']]);
        $this->app->make(ExceptionHandler::class)->report($exception);
        $events = $this->app->make(CaptureCallback::class)->events;
        $this->assertSame('user', $events[2]['distinct_id']);
        $this->assertSame(['team' => 'one'], $events[2]['properties']['$groups']);
        $this->assertFalse($events[2]['properties']['$exception_list'][0]['mechanism']['handled']);
    }

    public function testInstallPublishesWithoutOverwritingApplicationConfig(): void
    {
        $path = $this->app->configPath('posthog.php');
        $original = is_file($path) ? file_get_contents($path) : null;
        @unlink($path);
        try {
            $this->artisan('posthog:install')->assertSuccessful();
            $this->assertFileExists($path);
            file_put_contents($path, '<?php return ["application" => true];');
            $this->artisan('posthog:install')->assertSuccessful();
            $this->assertSame('<?php return ["application" => true];', file_get_contents($path));
        } finally {
            if ($original === null) {
                @unlink($path);
            } else {
                file_put_contents($path, $original);
            }
        }
    }

    public function testFakeDoesNotApplyCaptureContextToIdentityMutations(): void
    {
        $fake = Facade::fake();
        $this->app->make(Context::class)->begin([
            'distinctId' => 'ambient-user', 'properties' => ['private' => 'capture-only'],
        ]);
        Facade::identify(['distinctId' => 'target', 'properties' => ['plan' => 'pro']]);
        Facade::alias(['distinctId' => 'target', 'alias' => 'old']);
        Facade::groupIdentify(['groupType' => 'team', 'groupKey' => 'one']);
        $messages = array_column($fake->recorded(), 'message');
        $this->assertSame('target', $messages[0]['distinctId']);
        $this->assertSame(['plan' => 'pro'], $messages[0]['properties']);
        $this->assertSame([], $messages[1]['properties']);
        $this->assertNull($messages[2]['distinctId']);
        $this->assertSame([], $messages[2]['properties']);
    }

    public function testFakeForwardsAllMessageApisAndAssertions(): void
    {
        $previousClient = $this->app->make(Client::class);
        $fake = Facade::fake();
        $this->assertNotSame($previousClient, $this->app->make(Client::class));
        $this->assertSame($fake->client(), $this->app->make(Client::class));
        $fake->assertNothingCaptured();
        Facade::capture('event', ['count' => 1], 'user');
        Facade::identify(['distinctId' => 'user', 'properties' => ['plan' => 'paid']]);
        Facade::alias(['distinctId' => 'user', 'alias' => 'old']);
        Facade::groupIdentify(['groupType' => 'team', 'groupKey' => 'one']);
        Facade::captureException('manual');
        $fake->assertCaptured('event', fn ($message) => $message['properties']['count'] === 1);
        $fake->assertCapturedCount(1);
        $fake->assertNotCaptured('missing');
        $fake->assertIdentified();
        $fake->assertAliased();
        $fake->assertGroupIdentified();
        $fake->assertExceptionCaptured();
        $this->assertFalse($fake->client()->capture(['event' => 'escape', 'distinctId' => 'user']));
    }

    public function testCommandsNeverPrintKeysAndRequireExplicitSending(): void
    {
        $this->artisan('posthog:doctor')->expectsOutput(
            'PostHog is configured. Credentials are server-side. No network check was performed.',
        )->assertSuccessful();
        $this->artisan('posthog:test')->assertFailed();
        Facade::fake();
        $this->artisan('posthog:test', ['--send' => true])->assertSuccessful();
        $this->app['config']->set('posthog.api_key', null);
        $this->app->instance(PostHog::class, new PostHog($this->app));
        $this->artisan('posthog:doctor')->assertFailed();
    }
}
