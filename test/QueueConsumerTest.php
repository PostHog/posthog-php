<?php

namespace PostHog\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PostHog\Consumer\LibCurl;
use PostHog\Consumer\Socket;
use PostHog\QueueConsumer;

class QueueConsumerTest extends TestCase
{
    public function testRetryableFlushFailureKeepsBatchQueued(): void
    {
        $first = $this->message('first');
        $second = $this->message('second');
        $consumer = new QueueConsumerTestConsumer(
            [QueueConsumerTestConsumer::retryableFailure()],
            ['batch_size' => 2]
        );

        $this->assertTrue($consumer->enqueue($first));
        $this->assertFalse($consumer->enqueue($second));

        $this->assertSame([[$first, $second]], $consumer->flushedBatches);
        $this->assertSame([$first, $second], $consumer->queuedItems());
    }

    public function testNonRetryableFlushFailureDropsBatch(): void
    {
        $first = $this->message('first');
        $second = $this->message('second');
        $consumer = new QueueConsumerTestConsumer(
            [QueueConsumerTestConsumer::nonRetryableFailure()],
            ['batch_size' => 2]
        );

        $this->assertTrue($consumer->enqueue($first));
        $this->assertFalse($consumer->enqueue($second));

        $this->assertSame([[$first, $second]], $consumer->flushedBatches);
        $this->assertSame([], $consumer->queuedItems());
    }

    public function testFalseFlushBatchResultStillDropsBatchForCompatibility(): void
    {
        $first = $this->message('first');
        $second = $this->message('second');
        $consumer = new QueueConsumerTestConsumer([false], ['batch_size' => 2]);

        $this->assertTrue($consumer->enqueue($first));
        $this->assertFalse($consumer->enqueue($second));

        $this->assertSame([], $consumer->queuedItems());
    }

    public function testSocketConnectionFailureDropsBatch(): void
    {
        $message = $this->message('socket-connection-failure');
        $consumer = new Socket(
            'test-key',
            [
                'batch_size' => 1,
                'host' => 'invalid.invalid',
                'ssl' => false,
                'timeout' => 0.01,
            ]
        );

        $this->assertFalse($consumer->capture($message));

        $this->assertSame([], $this->queuedItems($consumer));
    }

    public function testRetainedFailedBatchIsRetriedBeforeNewerEvents(): void
    {
        $first = $this->message('first');
        $second = $this->message('second');
        $third = $this->message('third');
        $consumer = new QueueConsumerTestConsumer(
            [QueueConsumerTestConsumer::retryableFailure(), true],
            ['batch_size' => 2]
        );

        $this->assertTrue($consumer->enqueue($first));
        $this->assertFalse($consumer->enqueue($second));
        $this->assertTrue($consumer->enqueue($third));

        $this->assertSame([[$first, $second], [$first, $second], [$third]], $consumer->flushedBatches);
        $this->assertSame([], $consumer->queuedItems());
    }

    #[DataProvider('libCurlFailureQueueBehaviorCases')]
    public function testLibCurlFailureQueueBehavior(
        string $event,
        $batchEndpointResponse,
        int $batchEndpointResponseCode,
        int $batchEndpointCurlErrno,
        bool $shouldKeepQueued
    ): void {
        $message = $this->message($event);
        $httpClient = new MockedHttpClient(
            'app.posthog.com',
            batchEndpointResponse: $batchEndpointResponse,
            batchEndpointResponseCode: $batchEndpointResponseCode,
            batchEndpointCurlErrno: $batchEndpointCurlErrno
        );
        $consumer = new LibCurl('test-key', ['batch_size' => 1], $httpClient);

        $this->assertFalse($consumer->capture($message));

        $this->assertSame($shouldKeepQueued ? [$message] : [], $this->queuedItems($consumer));
    }

    public static function libCurlFailureQueueBehaviorCases(): array
    {
        return [
            'network failure keeps batch queued' => ['network-failure', false, 0, 28, true],
            'http failure drops batch' => ['http-failure', '{"status":0}', 500, 0, false],
            'payload too large drops batch' => ['payload-too-large', '{"status":0}', 413, 0, false],
        ];
    }

    private function message(string $event): array
    {
        return [
            'event' => $event,
            'library' => 'posthog-php',
            'library_version' => 'test',
        ];
    }

    private function queuedItems($consumer): array
    {
        $reflection = new \ReflectionClass(QueueConsumer::class);
        $queueProperty = $reflection->getProperty('queue');

        return $queueProperty->getValue($consumer);
    }
}
