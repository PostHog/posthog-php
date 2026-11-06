<?php

namespace PostHog\Laravel\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\WorkerOptions;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PostHog\Consumer\LibCurl;
use PostHog\HttpClient;
use PostHog\HttpResponse;
use PostHog\Laravel\Context;
use PostHog\Laravel\Delivery\BatchDelivery;
use PostHog\Laravel\Jobs\DeliverEvents;
use PostHog\Laravel\PostHog;
use PostHog\Laravel\PostHogServiceProvider;
use PostHog\Laravel\Tests\Fixtures\AmbientCaptureJob;
use ReflectionProperty;
use RuntimeException;
use Throwable;

class QueuedDeliverySafetyTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PostHogServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $app['config']->set('posthog.api_key', 'project-key');
        $app['config']->set('posthog.host', 'http://127.0.0.1:1');
        $app['config']->set('posthog.laravel_queue', [
            'enabled' => true, 'connection' => 'database', 'name' => 'analytics',
        ]);
        $app['config']->set('database.default', 'testing');
        foreach (['testing', 'queue'] as $name) {
            $app['config']->set('database.connections.' . $name, ['driver' => 'sqlite', 'database' => ':memory:']);
        }
        $app['config']->set('queue.connections.database', [
            'driver' => 'database', 'connection' => 'queue', 'table' => 'jobs', 'queue' => 'analytics',
            'retry_after' => 90, 'after_commit' => true,
        ]);
        $app['config']->set('queue.failed', [
            'driver' => 'database-uuids', 'database' => 'queue', 'table' => 'failed_jobs',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $schema = $this->app['db']->connection('queue')->getSchemaBuilder();
        $schema->create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue');
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        $schema->create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid');
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
        $this->app['db']->connection('testing')->getSchemaBuilder()->create('app_writes', function (Blueprint $table) {
            $table->id();
            $table->string('value');
        });
    }

    public function testTransportJobsDoNotRestoreAttributionRemovedByBeforeSend(): void
    {
        $this->app['config']->set('posthog.sdk.before_send', static function ($event) {
            $event['distinct_id'] = 'hashed-actor';
            unset(
                $event['properties']['$session_id'],
                $event['properties']['$groups'],
                $event['groups'],
                $event['$groups'],
            );
            return $event;
        });
        $this->app->make(Context::class)->begin([
            'distinctId' => 'private@example.test',
            'sessionId' => 'private-session',
            'groups' => ['org' => 'private-org'],
        ]);
        $manager = $this->app->make(PostHog::class);
        $manager->capture('sanitized');
        $this->assertTrue($manager->flush());
        $stored = $this->app['db']->connection('queue')->table('jobs')->sole()->payload;
        $payload = json_decode($stored, true);
        $this->assertArrayNotHasKey('_posthog', $payload);
        foreach (['private@example.test', 'private-session', 'private-org'] as $private) {
            $this->assertStringNotContainsString($private, $stored);
        }
        $events = $this->events($payload);
        $this->assertSame('hashed-actor', $events[0]['distinct_id']);
        $this->assertArrayNotHasKey('$session_id', $events[0]['properties']);
        $this->assertArrayNotHasKey('$groups', $events[0]['properties']);
        $this->app->make(\Illuminate\Contracts\Bus\Dispatcher::class)->dispatch(
            (new AmbientCaptureJob())->onConnection('database')->onQueue('ordinary'),
        );
        $ordinaryRow = $this->app['db']->connection('queue')->table('jobs')->where('queue', 'ordinary')->sole();
        $ordinary = json_decode($ordinaryRow->payload, true);
        $this->assertSame('private@example.test', $ordinary['_posthog']['distinctId']);
        $this->assertSame('private-session', $ordinary['_posthog']['properties']['$session_id']);
        $this->assertSame(['org' => 'private-org'], $ordinary['_posthog']['groups']);
    }

    public function testInvalidObjectPropertyNamesDoNotPoisonLaterCaptures(): void
    {
        $diagnostics = [];
        $this->app['config']->set('posthog.sdk.batch_size', 1);
        $this->app['config']->set('posthog.sdk.error_handler', static function ($code, $message) use (&$diagnostics) {
            $diagnostics[] = $message;
        });
        $manager = $this->app->make(PostHog::class);
        $properties = json_decode('{"\u0000attacker":"private-value"}', true, flags: JSON_THROW_ON_ERROR);
        $this->assertFalse($manager->capture('invalid', $properties, 'actor'));
        $this->assertTrue($manager->capture('valid', ['empty' => (object) []], 'actor'));
        $row = $this->app['db']->connection('queue')->table('jobs')->sole();
        $events = $this->events(json_decode($row->payload, true));
        $this->assertSame(['valid'], array_column($events, 'event'));
        $command = $this->command(json_decode($row->payload, true));
        $this->assertStringContainsString('"empty":{}', $command->events);
        $this->assertCount(1, $diagnostics);
        $this->assertStringNotContainsString('private-value', implode('', $diagnostics));
    }

    public function testDeliveryPayloadRemainsEncryptedInFailedJobStorage(): void
    {
        $manager = $this->app->make(PostHog::class);
        $manager->capture('private-event', ['private-field' => 'private-value'], 'private-actor');
        $this->assertTrue($manager->flush());
        $stored = $this->app['db']->connection('queue')->table('jobs')->sole()->payload;
        $this->assertStringNotContainsString('private-value', $stored);
        $this->assertSame('private-value', $this->events(json_decode($stored, true))[0]['properties']['private-field']);
        $http = $this->createStub(HttpClient::class);
        $http->method('sendRequest')->willReturn(new HttpResponse('{}', 400));
        $delivery = new BatchDelivery($this->app);
        (new ReflectionProperty(LibCurl::class, 'httpClient'))->setValue($delivery, $http);
        $this->app->instance(BatchDelivery::class, $delivery);
        $this->app['events']->listen(JobFailed::class, function ($event) {
            $this->app['queue.failer']->log(
                $event->connectionName,
                $event->job->getQueue(),
                $event->job->getRawBody(),
                $event->exception,
            );
        });
        $this->app['queue.worker']->runNextJob('database', 'analytics', new WorkerOptions(maxTries: 3));
        $this->assertSame(0, $this->app['queue']->connection('database')->size('analytics'));
        $failed = $this->app['db']->connection('queue')->table('failed_jobs')->sole()->payload;
        $this->assertStringNotContainsString('private-value', $failed);
        $this->assertSame('private-value', $this->events(json_decode($failed, true))[0]['properties']['private-field']);
    }

    public static function workerConfiguration(): array
    {
        return [
            'clean' => ['https://us.i.posthog.com', 'project-key'],
            'padded' => ['  https://us.i.posthog.com  ', '  project-key  '],
            'padded key' => ['https://us.i.posthog.com', '  project-key  '],
            'empty host' => ['', 'project-key'],
            'blank host' => ['   ', 'project-key'],
        ];
    }

    #[DataProvider('workerConfiguration')]
    public function testWorkerNormalizesHostAndCredentials(string $host, string $key): void
    {
        $this->app['config']->set('posthog.host', $host);
        $this->app['config']->set('posthog.api_key', $key);
        $delivery = new BatchDelivery($this->app);
        $native = (new ReflectionProperty(LibCurl::class, 'httpClient'))->getValue($delivery);
        $this->assertSame('us.i.posthog.com', (new ReflectionProperty(HttpClient::class, 'host'))->getValue($native));
        $http = $this->createMock(HttpClient::class);
        $http->expects($this->once())->method('sendRequest')->willReturnCallback(function ($path, $body) {
            $this->assertSame('project-key', json_decode($body, true)['api_key']);
            return new HttpResponse('{}', 200);
        });
        (new ReflectionProperty(LibCurl::class, 'httpClient'))->setValue($delivery, $http);
        $delivery->deliver('[{"event":"normalized","distinct_id":"actor"}]');
    }

    public static function deferredDiagnostics(): array
    {
        return ['normal diagnostics' => [false], 'throwing diagnostics' => [true]];
    }

    #[DataProvider('deferredDiagnostics')]
    public function testDeferredQueueOutageDoesNotEscapeCommitAndCanBeRetried(bool $throwingDiagnostics): void
    {
        $prepared = [];
        $this->app['config']->set('posthog.sdk.before_send', static function ($event) use (&$prepared) {
            $prepared = [$event['uuid'], $event['timestamp']];
            return $event;
        });
        if ($throwingDiagnostics) {
            $this->app['config']->set('posthog.sdk.error_handler', static function () {
                throw new RuntimeException('diagnostic failure');
            });
        }
        $queue = $this->controlledQueue();
        $queue->unavailable = true;
        $manager = $this->app->make(PostHog::class);
        $connection = $this->app['db']->connection('testing');
        $failure = null;
        $mutable = (object) ['state' => 'original'];
        try {
            $connection->transaction(function () use ($manager, $connection, $mutable) {
                $connection->table('app_writes')->insert(['value' => 'committed']);
                $manager->capture('deferred', ['empty' => (object) [], 'mutable' => $mutable], 'actor');
                $this->assertTrue($manager->flush());
                $mutable->state = 'changed';
                $this->assertSame(0, $this->app['queue']->connection('database')->size('analytics'));
            });
        } catch (Throwable $exception) {
            $failure = $exception;
        }
        $this->assertNull($failure, 'Telemetry queue failure escaped database commit.');
        $this->assertSame('committed', $connection->table('app_writes')->sole()->value);
        $queue->unavailable = false;
        $this->assertTrue($manager->flush());
        $stored = $this->app['db']->connection('queue')->table('jobs')->sole()->payload;
        $event = $this->events(json_decode($stored, true))[0];
        $this->assertSame('deferred', $event['event']);
        $this->assertSame($prepared, [$event['uuid'], $event['timestamp']]);
        $this->assertSame('original', $event['properties']['mutable']['state']);
        $this->assertStringContainsString('"empty":{}', $this->command(json_decode($stored, true))->events);
    }

    public function testDeferredCommitAndRollbackKeepLaravelSemantics(): void
    {
        $this->controlledQueue();
        $manager = $this->app->make(PostHog::class);
        $connection = $this->app['db']->connection('testing');
        $connection->beginTransaction();
        $connection->beginTransaction();
        $manager->capture('committed', distinctId: 'actor');
        $manager->flush();
        $connection->commit();
        $this->assertSame(0, $this->app['queue']->connection('database')->size('analytics'));
        $connection->commit();
        $this->assertSame(1, $this->app['queue']->connection('database')->size('analytics'));
        $connection->beginTransaction();
        $manager->capture('rolled back', distinctId: 'actor');
        $manager->flush();
        $connection->rollBack();
        $manager->flush();
        $this->assertSame(1, $this->app['queue']->connection('database')->size('analytics'));
    }

    public function testQueueWritesFailClosedWithoutAnEncrypter(): void
    {
        $this->app->offsetUnset('encrypter');
        $manager = $this->app->make(PostHog::class);
        $manager->capture('requires encryption', distinctId: 'actor');
        $this->assertFalse($manager->flush());
        $this->assertSame(0, $this->app['queue']->connection('database')->size('analytics'));
        $this->app->register(\Illuminate\Encryption\EncryptionServiceProvider::class, true);
        $this->assertTrue($manager->flush());
        $this->assertSame(1, $this->app['queue']->connection('database')->size('analytics'));
    }

    public function testQueueWritesFailClosedWithoutAnEncryptionKey(): void
    {
        $this->app['config']->set('app.key', '');
        $manager = $this->app->make(PostHog::class);
        $manager->capture('requires encryption', distinctId: 'actor');
        $this->assertFalse($manager->flush());
        $this->assertSame(0, $this->app['queue']->connection('database')->size('analytics'));
        $this->app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $this->assertTrue($manager->flush());
        $this->assertSame(1, $this->app['queue']->connection('database')->size('analytics'));
    }

    public function testUndecryptableDeliveryJobDoesNotEnqueueAnExceptionEvent(): void
    {
        $manager = $this->app->make(PostHog::class);
        $manager->capture('encrypted', distinctId: 'actor');
        $manager->flush();
        $this->app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('m', 32)));
        $this->app->forgetInstance('encrypter');
        $delivery = new BatchDelivery($this->app);
        $http = $this->createMock(HttpClient::class);
        $http->expects($this->never())->method('sendRequest');
        (new ReflectionProperty(LibCurl::class, 'httpClient'))->setValue($delivery, $http);
        $this->app->instance(BatchDelivery::class, $delivery);
        $this->app['queue.worker']->runNextJob('database', 'analytics', new WorkerOptions(backoff: 0, maxTries: 3));
        $manager->flush();
        $this->assertSame(1, $this->app['queue']->connection('database')->size('analytics'));
    }

    public function testImmediateQueueFailureRetainsTheBatchWithAfterCommitConfigured(): void
    {
        $queue = $this->controlledQueue();
        $queue->unavailable = true;
        $manager = $this->app->make(PostHog::class);
        $manager->capture('immediate', distinctId: 'actor');
        $this->assertFalse($manager->flush());
        $queue->unavailable = false;
        $this->assertTrue($manager->flush());
        $stored = $this->app['db']->connection('queue')->table('jobs')->sole()->payload;
        $this->assertSame('immediate', $this->events(json_decode($stored, true))[0]['event']);
    }

    public function testBeforeCommitConnectionStillWritesImmediately(): void
    {
        $this->controlledQueue(false);
        $manager = $this->app->make(PostHog::class);
        $connection = $this->app['db']->connection('testing');
        $connection->beginTransaction();
        $manager->capture('immediate by configuration', distinctId: 'actor');
        $this->assertTrue($manager->flush());
        $this->assertSame(1, $this->app['queue']->connection('database')->size('analytics'));
        $connection->rollBack();
        $this->assertSame(1, $this->app['queue']->connection('database')->size('analytics'));
    }

    public function testEmptyConnectionUsesDefaultAfterCommitProtection(): void
    {
        $queue = $this->controlledQueue();
        $queue->unavailable = true;
        $this->app['config']->set('queue.default', 'database');
        $this->app['config']->set('posthog.laravel_queue.connection', '');
        $manager = $this->app->make(PostHog::class);
        $connection = $this->app['db']->connection('testing');
        $failure = null;
        try {
            $connection->transaction(function () use ($manager, $connection) {
                $connection->table('app_writes')->insert(['value' => 'committed']);
                $manager->capture('default connection', distinctId: 'actor');
                $this->assertTrue($manager->flush());
            });
        } catch (Throwable $exception) {
            $failure = $exception;
        }
        $this->assertNull($failure, 'Default-connection queue failure escaped commit.');
        $this->assertSame('committed', $connection->table('app_writes')->sole()->value);
        $queue->unavailable = false;
        $this->assertTrue($manager->flush());
        $stored = $this->app['db']->connection('queue')->table('jobs')->sole()->payload;
        $this->assertSame('default connection', $this->events(json_decode($stored, true))[0]['event']);
    }

    public function testDeferredBatchesShareCapacityWithBufferedEvents(): void
    {
        $this->app['config']->set('posthog.sdk.max_queue_size', 3);
        $this->app['config']->set('posthog.sdk.batch_size', 2);
        $manager = $this->app->make(PostHog::class);
        $connection = $this->app['db']->connection('testing');
        $connection->beginTransaction();
        try {
            foreach (['first', 'second', 'third'] as $event) {
                $this->assertTrue($manager->capture($event, distinctId: 'actor'));
            }
            $this->assertFalse($manager->capture('over capacity', distinctId: 'actor'));
            $this->assertTrue($manager->flush());
            $this->assertFalse($manager->capture('still over capacity', distinctId: 'actor'));
            $connection->commit();
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }
        $events = [];
        foreach ($this->app['db']->connection('queue')->table('jobs')->orderBy('id')->get() as $row) {
            $events = array_merge($events, array_column($this->events(json_decode($row->payload, true)), 'event'));
        }
        $this->assertSame(['first', 'second', 'third'], $events);
        $this->assertTrue($manager->capture('after commit', distinctId: 'actor'));
        $this->assertTrue($manager->flush());
    }

    public static function rollbackScopes(): array
    {
        return ['root transaction' => [false], 'committed savepoint' => [true]];
    }

    #[DataProvider('rollbackScopes')]
    public function testDeferredCapacityIsReleasedOnRollback(bool $savepoint): void
    {
        $this->app['config']->set('posthog.sdk.max_queue_size', 2);
        $this->app['config']->set('posthog.sdk.batch_size', 1);
        $manager = $this->app->make(PostHog::class);
        $connection = $this->app['db']->connection('testing');
        $connection->beginTransaction();
        try {
            if ($savepoint) {
                $connection->beginTransaction();
            }
            $this->assertTrue($manager->capture('first', distinctId: 'actor'));
            $this->assertTrue($manager->capture('second', distinctId: 'actor'));
            $this->assertFalse($manager->capture('over capacity', distinctId: 'actor'));
            if ($savepoint) {
                $connection->commit();
                $this->assertFalse($manager->capture('outer still pending', distinctId: 'actor'));
            }
        } finally {
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }
        $this->assertTrue($manager->capture('after rollback', distinctId: 'actor'));
        $stored = $this->app['db']->connection('queue')->table('jobs')->sole()->payload;
        $this->assertSame('after rollback', $this->events(json_decode($stored, true))[0]['event']);
    }

    public function testSavepointRollbackReleasesOnlyItsOwnDeferredCapacity(): void
    {
        $this->app['config']->set('posthog.sdk.max_queue_size', 2);
        $this->app['config']->set('posthog.sdk.batch_size', 1);
        $manager = $this->app->make(PostHog::class);
        $connection = $this->app['db']->connection('testing');
        $connection->beginTransaction();
        try {
            $this->assertTrue($manager->capture('outer', distinctId: 'actor'));
            $connection->beginTransaction();
            $this->assertTrue($manager->capture('inner', distinctId: 'actor'));
            $connection->rollBack();
            $this->assertTrue($manager->capture('replacement', distinctId: 'actor'));
            $this->assertFalse($manager->capture('over capacity', distinctId: 'actor'));
            $connection->commit();
        } finally {
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }
        $events = [];
        foreach ($this->app['db']->connection('queue')->table('jobs')->orderBy('id')->get() as $row) {
            $events = array_merge($events, array_column($this->events(json_decode($row->payload, true)), 'event'));
        }
        $this->assertSame(['outer', 'replacement'], $events);
    }

    public function testFailedDeferredWritesContinueToConsumeCapacityUntilRetried(): void
    {
        $queue = $this->controlledQueue();
        $queue->unavailable = true;
        $this->app['config']->set('posthog.sdk.max_queue_size', 2);
        $this->app['config']->set('posthog.sdk.batch_size', 1);
        $manager = $this->app->make(PostHog::class);
        $connection = $this->app['db']->connection('testing');
        $connection->beginTransaction();
        try {
            $this->assertTrue($manager->capture('first', distinctId: 'actor'));
            $this->assertTrue($manager->capture('second', distinctId: 'actor'));
            $this->assertFalse($manager->capture('over capacity', distinctId: 'actor'));
            $connection->commit();
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }
        $this->assertFalse($manager->capture('still over capacity', distinctId: 'actor'));
        $queue->unavailable = false;
        $this->assertTrue($manager->flush());
        $this->assertSame(2, $this->app['queue']->connection('database')->size('analytics'));
        $this->assertTrue($manager->capture('after retry', distinctId: 'actor'));
    }

    private function command(array $payload): DeliverEvents
    {
        return unserialize($this->app['encrypter']->decrypt($payload['data']['command']));
    }

    private function events(array $payload): array
    {
        return json_decode($this->command($payload)->events, true);
    }

    private function controlledQueue(bool $afterCommit = true): DatabaseQueue
    {
        $this->app['config']->set('queue.connections.database.after_commit', $afterCommit);
        $queue = new class (
            $this->app['db']->connection('queue'),
            'jobs',
            'analytics',
            90,
            $afterCommit,
        ) extends DatabaseQueue {
            public bool $unavailable = false;

            protected function pushToDatabase($queue, $payload, $delay = 0, $attempts = 0)
            {
                if ($this->unavailable) {
                    throw new RuntimeException('queue unavailable');
                }
                return parent::pushToDatabase($queue, $payload, $delay, $attempts);
            }
        };
        $this->app['queue']->addConnector(
            'controlled',
            static fn () => new class ($queue) implements ConnectorInterface {
                public function __construct(private DatabaseQueue $queue)
                {
                }

                public function connect(array $config)
                {
                    return $this->queue;
                }
            },
        );
        $this->app['config']->set('queue.connections.database.driver', 'controlled');
        return $queue;
    }
}
