<?php

namespace PostHog\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PostHog\Consumer\ForkCurl;
use PostHog\Consumer\LibCurl;
use PostHog\Consumer\Socket;
use PostHog\QueueConsumer;

class QueueConsumerTest extends TestCase
{
    public function testDefaultQueueAcceptsTenThousandItemsAndRejectsTheNext(): void
    {
        $consumer = new QueueConsumerTestConsumer(
            [],
            ['batch_size' => 20_000, 'flush_interval_seconds' => 3600]
        );

        for ($i = 0; $i < 10_000; ++$i) {
            $consumer->enqueue($i);
        }

        $this->assertCount(10_000, $consumer->queuedItems());
        $this->assertFalse($consumer->enqueue('overflow'));
        $this->assertCount(10_000, $consumer->queuedItems());
    }

    public function testBatchPayloadSizeLimitAppliesToRawJson(): void
    {
        $consumer = new QueueConsumerTestConsumer([]);

        $emptyPayload = $consumer->encodedBatchPayload([['event' => '']]);
        $this->assertIsString($emptyPayload);

        $remainingBytes = (1024 * 1024) - strlen($emptyPayload);
        $belowLimit = [['event' => str_repeat('x', $remainingBytes - 1)]];
        $atLimit = [['event' => str_repeat('x', $remainingBytes)]];

        $this->assertIsString($consumer->encodedBatchPayload($belowLimit));
        $this->assertFalse($consumer->encodedBatchPayload($atLimit));
    }

    public function testBatchPayloadEncodingFailureIsNotSendable(): void
    {
        $error = null;
        $consumer = new QueueConsumerTestConsumer(
            [],
            [
                'error_handler' => static function ($code, $message) use (&$error): void {
                    $error = [$code, $message];
                },
            ]
        );

        $this->assertFalse($consumer->encodedBatchPayload([['event' => "\xB1\x31"]]));
        $this->assertSame(JSON_ERROR_UTF8, $error[0]);
        $this->assertStringContainsString('Failed to encode batch payload', $error[1]);
    }

    public function testLibCurlRejectsOversizedPayloadBeforeSendingRequest(): void
    {
        $httpClient = new MockedHttpClient('app.posthog.com');
        $consumer = new LibCurl('test-key', ['compress_request' => true], $httpClient);

        $result = $consumer->flushBatch([['event' => str_repeat('x', 1024 * 1024)]]);

        $this->assertSame('non_retryable_failure', $result);
        $this->assertNull($httpClient->calls);
    }

    public function testForkCurlRejectsExpandedShellPayloadWithoutLeakingTempFile(): void
    {
        $before = glob('/tmp/forkcurl_*') ?: [];
        $consumer = new ForkCurl('test-key', ['compress_request' => true]);
        $marker = bin2hex(random_bytes(16));

        // The raw JSON is below 1 MiB, but shell escaping expands each apostrophe.
        $result = $consumer->flushBatch([['event' => $marker . str_repeat("'", 300_000)]]);

        $after = glob('/tmp/forkcurl_*') ?: [];
        $createdByTest = array_values(array_filter(
            array_diff($after, $before),
            static function ($file) use ($marker): bool {
                $compressed = @file_get_contents($file);
                $payload = false !== $compressed ? @gzdecode($compressed) : false;
                return false !== $payload && str_contains($payload, $marker);
            }
        ));
        foreach ($createdByTest as $file) {
            @unlink($file);
        }

        $this->assertSame('non_retryable_failure', $result);
        $this->assertSame([], $createdByTest);
    }

    public function testSocketRejectsOversizedPayloadBeforeOpeningConnection(): void
    {
        $networkAttempted = false;
        $consumer = new Socket(
            'test-key',
            [
                'compress_request' => true,
                'host' => 'invalid.invalid',
                'ssl' => false,
                'timeout' => 0.01,
                'error_handler' => static function () use (&$networkAttempted): void {
                    $networkAttempted = true;
                },
            ]
        );

        $result = $consumer->flushBatch([['event' => str_repeat('x', 1024 * 1024)]]);

        $this->assertSame('non_retryable_failure', $result);
        $this->assertFalse($networkAttempted);
    }

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
