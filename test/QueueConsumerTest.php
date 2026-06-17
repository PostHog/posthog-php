<?php

namespace PostHog\Test;

use PHPUnit\Framework\TestCase;
use PostHog\Consumer\LibCurl;
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

    public function testLibCurlNetworkFailureKeepsBatchQueued(): void
    {
        $message = $this->message('network-failure');
        $httpClient = new MockedHttpClient(
            'app.posthog.com',
            batchEndpointResponse: false,
            batchEndpointResponseCode: 0,
            batchEndpointCurlErrno: 28
        );
        $consumer = new LibCurl('test-key', ['batch_size' => 1], $httpClient);

        $this->assertFalse($consumer->capture($message));

        $this->assertSame([$message], $this->queuedItems($consumer));
    }

    public function testLibCurlHttpFailureDropsBatch(): void
    {
        $message = $this->message('http-failure');
        $httpClient = new MockedHttpClient(
            'app.posthog.com',
            batchEndpointResponse: '{"status":0}',
            batchEndpointResponseCode: 500
        );
        $consumer = new LibCurl('test-key', ['batch_size' => 1], $httpClient);

        $this->assertFalse($consumer->capture($message));

        $this->assertSame([], $this->queuedItems($consumer));
    }

    public function testLibCurlPayloadTooLargeDropsBatch(): void
    {
        $message = $this->message('payload-too-large');
        $httpClient = new MockedHttpClient(
            'app.posthog.com',
            batchEndpointResponse: '{"status":0}',
            batchEndpointResponseCode: 413
        );
        $consumer = new LibCurl('test-key', ['batch_size' => 1], $httpClient);

        $this->assertFalse($consumer->capture($message));

        $this->assertSame([], $this->queuedItems($consumer));
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
