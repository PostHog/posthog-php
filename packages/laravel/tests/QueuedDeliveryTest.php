<?php

namespace PostHog\Laravel\Tests;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Bus;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PostHog\Client;
use PostHog\Consumer\LibCurl;
use PostHog\HttpClient;
use PostHog\HttpResponse;
use PostHog\Laravel\Context;
use PostHog\Laravel\Delivery\BatchDelivery;
use PostHog\Laravel\Delivery\DeliveryFailed;
use PostHog\Laravel\Jobs\DeliverEvents;
use PostHog\Laravel\PostHog;
use PostHog\Laravel\PostHogServiceProvider;
use ReflectionProperty;
use RuntimeException;

class QueuedDeliveryTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PostHogServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('posthog.api_key', 'project-key');
        $app['config']->set('posthog.queue', [
            'enabled' => true, 'connection' => 'redis', 'queue' => 'analytics',
        ]);
    }

    public function testFlushQueuesPreparedEventsWithoutSendingHttpInTheProducer(): void
    {
        Bus::fake();
        $this->app['config']->set('posthog.secret_key', 'server-secret');
        $manager = $this->app->make(PostHog::class);
        $http = $this->createMock(HttpClient::class);
        $http->expects($this->never())->method('sendRequest');
        $consumer = (new ReflectionProperty(Client::class, 'consumer'))->getValue($manager->client());
        (new ReflectionProperty(LibCurl::class, 'httpClient'))->setValue($consumer, $http);
        $this->app->make(Context::class)->begin([
            'distinctId' => 'original-user', 'sessionId' => 'original-session',
            'groups' => ['team' => 'original-team'], 'properties' => ['$ip' => '198.51.100.23'],
        ]);
        $this->assertTrue($manager->capture('first', ['nested' => (object) []]));
        $this->assertTrue($manager->capture('second'));
        Bus::assertNothingDispatched();
        $this->assertTrue($manager->flush());
        Bus::assertDispatchedTimes(DeliverEvents::class, 1);
        Bus::assertDispatched(DeliverEvents::class, function ($job) {
            $events = json_decode($job->events, true);
            $this->assertSame(['first', 'second'], array_column($events, 'event'));
            $this->assertSame('original-user', $events[0]['distinct_id']);
            $this->assertSame('original-session', $events[0]['properties']['$session_id']);
            $this->assertSame('original-team', $events[0]['properties']['$groups']['team']);
            $this->assertSame('198.51.100.23', $events[0]['properties']['$ip']);
            $this->assertSame('posthog-laravel', $events[0]['properties']['$lib']);
            $this->assertNotEmpty($events[0]['uuid']);
            $this->assertNotEmpty($events[0]['timestamp']);
            $this->assertStringContainsString('"nested":{}', $job->events);
            $this->assertStringNotContainsString('project-key', serialize($job));
            $this->assertStringNotContainsString('server-secret', serialize($job));
            return $job->connection === 'redis' && $job->queue === 'analytics';
        });
    }

    public function testWorkerRetriesTheSerializedBatchWithoutChangingAttributionOrRerunningHooks(): void
    {
        Bus::fake();
        $calls = 0;
        $this->app['config']->set('posthog.sdk.before_send', function ($event) use (&$calls) {
            $calls++;
            unset($event['properties']['secret']);
            return $event;
        });
        $manager = $this->app->make(PostHog::class);
        $this->app->make(Context::class)->begin(['distinctId' => 'original-user', 'sessionId' => 'original-session']);
        $manager->capture('frozen', ['secret' => 'never-persist', 'nested' => (object) []]);
        $manager->flush();
        $job = unserialize(serialize(Bus::dispatched(DeliverEvents::class)->sole()));
        $expected = json_decode($job->events, true);
        $this->assertStringNotContainsString('never-persist', $job->events);
        $this->app->make(Context::class)->begin(['distinctId' => 'worker-user', 'sessionId' => 'worker-session']);
        $requests = [];
        $this->delivery([503, 200], $requests);
        try {
            $job->handle($this->app);
            $this->fail('A retryable delivery failure must escape the job handler.');
        } catch (DeliveryFailed $exception) {
            $this->assertTrue($exception->retryable);
            $this->app->make(ExceptionHandler::class)->report($exception);
        }
        $job->handle($this->app);
        $manager->flush();
        $this->assertCount(2, $requests);
        foreach ($requests as [$path, $body, $headers, $options]) {
            $this->assertSame('/batch/', $path);
            $this->assertStringContainsString('"nested":{}', $body);
            $this->assertSame($expected, json_decode($body, true)['batch']);
            $this->assertSame('project-key', json_decode($body, true)['api_key']);
            $this->assertTrue($options['shouldVerify']);
        }
        $this->assertSame(1, $calls);
        Bus::assertDispatchedTimes(DeliverEvents::class, 1);
    }

    public function testTerminalHttpFailureMarksTheLaravelJobFailed(): void
    {
        $requests = [];
        $this->delivery([400], $requests);
        $job = (new DeliverEvents('[{"event":"rejected","distinct_id":"actor"}]'))->withFakeQueueInteractions();
        $job->handle($this->app);
        $job->assertFailedWith(DeliveryFailed::class);
        $this->assertFalse($job->job->failedWith->retryable);
        $this->assertCount(1, $requests);
    }

    public function testQueueOutageReturnsFalseAndKeepsTheBatchForALaterFlush(): void
    {
        $jobs = [];
        $attempts = 0;
        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->expects($this->exactly(2))->method('dispatch')->willReturnCallback(
            function ($job) use (&$jobs, &$attempts) {
                $jobs[] = $job;
                if (++$attempts === 1) {
                    throw new RuntimeException('queue unavailable');
                }
                return 'job-id';
            },
        );
        $this->app->instance(Dispatcher::class, $dispatcher);
        $manager = $this->app->make(PostHog::class);
        $this->assertTrue($manager->capture('retained', distinctId: 'actor'));
        $this->assertFalse($manager->flush());
        $this->assertTrue($manager->flush());
        $this->assertSame($jobs[0]->events, $jobs[1]->events);
    }

    public function testRealDatabaseWorkerRetriesWithoutAutocapturingItsOwnDeliveryJobs(): void
    {
        $queue = $this->databaseQueue();
        $requests = [];
        $this->delivery([503, 200], $requests);
        $this->app['config']->set('posthog.autocapture.jobs.enabled', true);
        $manager = $this->app->make(PostHog::class);
        $manager->capture('durable', distinctId: 'actor');
        $manager->flush();
        $this->assertSame(1, $queue->size('analytics'));
        $worker = $this->app['queue.worker'];
        $options = new WorkerOptions(backoff: 0, maxTries: 3);
        $worker->runNextJob('database', 'analytics', $options);
        $this->assertSame(1, $queue->size('analytics'));
        $this->assertSame(1, $this->app['db']->table('jobs')->sole()->attempts);
        $worker->runNextJob('database', 'analytics', $options);
        $this->assertSame(0, $queue->size('analytics'));
        $this->assertCount(2, $requests);
        $this->assertSame($requests[0][1], $requests[1][1]);
    }

    public function testExhaustedInternalJobDoesNotGenerateAnotherAnalyticsJob(): void
    {
        $queue = $this->databaseQueue();
        $manager = $this->app->make(PostHog::class);
        $manager->capture('original', distinctId: 'actor');
        $manager->flush();
        $job = $queue->pop('analytics');
        $this->app->make(ExceptionHandler::class)->report(MaxAttemptsExceededException::forJob($job));
        $manager->flush();
        $this->assertSame(1, $queue->size('analytics'));
        $job->delete();
    }

    public function testDirectDeliveryRemainsTheDefault(): void
    {
        Bus::fake();
        $this->app['config']->set('posthog.queue.enabled', false);
        $manager = $this->app->make(PostHog::class);
        $consumer = (new ReflectionProperty(Client::class, 'consumer'))->getValue($manager->client());
        $http = $this->createMock(HttpClient::class);
        $http->expects($this->once())->method('sendRequest')->willReturn(new HttpResponse('{}', 200));
        (new ReflectionProperty(LibCurl::class, 'httpClient'))->setValue($consumer, $http);
        $this->assertTrue($manager->capture('direct', distinctId: 'actor'));
        $this->assertTrue($manager->flush());
        Bus::assertNothingDispatched();
    }

    public static function disabledConfigurations(): array
    {
        return [
            'disabled SDK' => ['posthog.enabled', false],
            'missing key' => ['posthog.api_key', ''],
            'noop consumer' => ['posthog.sdk.consumer', 'noop'],
        ];
    }

    #[DataProvider('disabledConfigurations')]
    public function testDisabledConfigurationsDoNotEnqueueJobs(string $key, mixed $value): void
    {
        Bus::fake();
        $this->app['config']->set($key, $value);
        $manager = $this->app->make(PostHog::class);
        $this->assertFalse($manager->capture('disabled', distinctId: 'actor'));
        $manager->flush();
        Bus::assertNothingDispatched();
    }

    public function testFeatureFlagsStaySynchronousWhileExposureEventsUseTheQueue(): void
    {
        Bus::fake();
        $manager = $this->app->make(PostHog::class);
        $http = $this->createMock(HttpClient::class);
        $http->expects($this->once())->method('sendRequest')->willReturnCallback(function ($path) {
            $this->assertSame('/flags/?v=2', $path);
            return new HttpResponse('{"flags":{"beta":{"enabled":true,"variant":null}}}', 200);
        });
        $manager->client()->httpClient = $http;
        $this->assertTrue($manager->evaluateFlags('actor')->isEnabled('beta'));
        $manager->flush();
        Bus::assertDispatched(DeliverEvents::class, fn ($job) =>
            array_column(json_decode($job->events, true), 'event') === ['$feature_flag_called']);
        Bus::assertDispatchedTimes(DeliverEvents::class, 1);
    }

    public function testEventProducingMethodsAllUseQueuedDelivery(): void
    {
        Bus::fake();
        $manager = $this->app->make(PostHog::class);
        $manager->identify(['distinctId' => 'actor', 'properties' => ['name' => 'example']]);
        $manager->alias(['distinctId' => 'actor', 'alias' => 'previous']);
        $manager->groupIdentify(['groupType' => 'team', 'groupKey' => 'one', 'properties' => ['name' => 'team']]);
        $manager->captureException('example failure', 'actor');
        $this->assertTrue($manager->flush());
        Bus::assertDispatched(DeliverEvents::class, fn ($job) =>
            array_column(json_decode($job->events, true), 'event') === [
                '$identify', '$create_alias', '$groupidentify', '$exception',
            ]);
        Bus::assertDispatchedTimes(DeliverEvents::class, 1);
    }

    public function testWorkerUsesVerifiedCompressedHttpAndMillisecondTimeouts(): void
    {
        $this->app['config']->set('posthog.sdk', [
            'consumer' => 'socket', 'timeout' => 0.25, 'http_timeout_ms' => 1250,
            'compress_request' => true, 'verify_batch_events_request' => false,
        ]);
        $delivery = new BatchDelivery($this->app);
        $native = (new ReflectionProperty(LibCurl::class, 'httpClient'))->getValue($delivery);
        $this->assertSame(
            1250,
            (new ReflectionProperty(HttpClient::class, 'curlTimeoutMilliseconds'))->getValue($native)
        );
        $http = $this->createMock(HttpClient::class);
        $http->expects($this->once())->method('sendRequest')->willReturnCallback(
            function ($path, $body, $headers, $options) {
                $this->assertTrue($options['shouldVerify']);
                $this->assertTrue($options['compressRequest']);
                $this->assertSame('compressed', json_decode(gzdecode($body), true)['batch'][0]['event']);
                $this->assertSame(['User-Agent: posthog-laravel/' . PostHog::VERSION], $headers);
                return new HttpResponse('{}', 200);
            },
        );
        (new ReflectionProperty(LibCurl::class, 'httpClient'))->setValue($delivery, $http);
        $delivery->deliver('[{"event":"compressed","distinct_id":"actor"}]');
    }

    public function testBeforeSendCanDropEventsBeforeTheyReachLaravelStorage(): void
    {
        Bus::fake();
        $this->app['config']->set('posthog.sdk.before_send', static fn () => null);
        $manager = $this->app->make(PostHog::class);
        $this->assertFalse($manager->capture('private', distinctId: 'actor'));
        $this->assertTrue($manager->flush());
        Bus::assertNothingDispatched();
    }

    public function testReentrantLifecycleFlushDoesNotDispatchTheSameBatchTwice(): void
    {
        $dispatches = 0;
        $manager = $this->app->make(PostHog::class);
        $dispatcher = $this->createStub(Dispatcher::class);
        $dispatcher->method('dispatch')->willReturnCallback(function () use ($manager, &$dispatches) {
            if (++$dispatches === 1) {
                $this->assertTrue($manager->flush());
            }
            return 'job-id';
        });
        $this->app->instance(Dispatcher::class, $dispatcher);
        $manager->capture('one batch', distinctId: 'actor');
        $this->assertTrue($manager->flush());
        $this->assertSame(1, $dispatches);
    }

    public function testSyncQueueDeliversInlineWithoutRecursingOrLeakingContext(): void
    {
        $this->app['config']->set('posthog.queue.connection', 'sync');
        $this->app['config']->set('posthog.sdk.batch_size', 1);
        $this->app['config']->set('posthog.autocapture.jobs.enabled', true);
        $requests = [];
        $this->delivery([200, 200], $requests);
        $manager = $this->app->make(PostHog::class);
        $context = $this->app->make(Context::class);
        foreach (['first', 'second'] as $id) {
            $context->begin(['distinctId' => $id]);
            $this->assertTrue($manager->capture('sync'));
            $this->assertSame($id, $context->data()['distinctId']);
        }
        $this->assertCount(2, $requests);
        $this->assertSame('first', json_decode($requests[0][1], true)['batch'][0]['distinct_id']);
        $this->assertSame('second', json_decode($requests[1][1], true)['batch'][0]['distinct_id']);
    }

    public function testBatchSizeTriggersQueueHandoffWithoutAnExplicitFlush(): void
    {
        Bus::fake();
        $this->app['config']->set('posthog.sdk.batch_size', 2);
        $manager = $this->app->make(PostHog::class);
        $manager->capture('first', distinctId: 'actor');
        Bus::assertNothingDispatched();
        $manager->capture('second', distinctId: 'actor');
        Bus::assertDispatchedTimes(DeliverEvents::class, 1);
        Bus::assertDispatched(DeliverEvents::class, fn ($job) =>
            array_column(json_decode($job->events, true), 'event') === ['first', 'second']);
    }

    public function testWorkerRespectsDisabledOrNoopConfigurationAndRejectsAMissingKey(): void
    {
        $requests = [];
        $this->delivery([200], $requests);
        $job = new DeliverEvents('[{"event":"pending","distinct_id":"actor"}]');
        $this->app['config']->set('posthog.enabled', false);
        $job->handle($this->app);
        $this->assertSame([], $requests);
        $this->app['config']->set('posthog.enabled', true);
        $this->app['config']->set('posthog.sdk.consumer', 'noop');
        $job->handle($this->app);
        $this->assertSame([], $requests);
        $this->app['config']->set('posthog.sdk.consumer', 'lib_curl');
        $this->app['config']->set('posthog.api_key', '');
        $this->app->forgetInstance(BatchDelivery::class);
        $this->expectException(DeliveryFailed::class);
        $this->expectExceptionMessage('requires a project API key');
        $job->handle($this->app);
    }

    public function testMissingConnectionAndQueueUseTheLaravelDefaults(): void
    {
        $queue = $this->databaseQueue('default-events');
        $this->app['config']->set('queue.default', 'database');
        $this->app['config']->set('posthog.queue.connection', null);
        $this->app['config']->set('posthog.queue.queue', null);
        $manager = $this->app->make(PostHog::class);
        $manager->capture('default queue', distinctId: 'actor');
        $this->assertTrue($manager->flush());
        $this->assertSame(1, $queue->size('default-events'));
        $this->assertSame(0, $queue->size('analytics'));
    }

    private function delivery(array $statuses, array &$requests): BatchDelivery
    {
        $delivery = new BatchDelivery($this->app);
        $http = $this->createStub(HttpClient::class);
        $http->method('sendRequest')->willReturnCallback(
            static function ($path, $body, $headers, $options) use (&$statuses, &$requests) {
                $requests[] = [$path, $body, $headers, $options];
                return new HttpResponse('{}', array_shift($statuses));
            },
        );
        (new ReflectionProperty(LibCurl::class, 'httpClient'))->setValue($delivery, $http);
        $this->app->instance(BatchDelivery::class, $delivery);
        return $delivery;
    }

    private function databaseQueue(string $defaultQueue = 'analytics'): \Illuminate\Contracts\Queue\Queue
    {
        $this->app['config']->set('database.default', 'testing');
        $this->app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:']);
        $this->app['config']->set('queue.connections.database', [
            'driver' => 'database', 'connection' => 'testing', 'table' => 'jobs', 'queue' => $defaultQueue,
            'retry_after' => 90, 'after_commit' => false,
        ]);
        $this->app['config']->set('posthog.queue.connection', 'database');
        $this->app['db']->connection()->getSchemaBuilder()->create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        return $this->app['queue']->connection('database');
    }
}
