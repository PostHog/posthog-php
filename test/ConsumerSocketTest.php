<?php

namespace PostHog\Test;

use RuntimeException;

class ConsumerSocketTest extends ConsumerTransportTestCase
{
    protected function consumer(): string
    {
        return 'socket';
    }

    public function testShortTimeout(): void
    {
        $client = $this->client(['timeout' => 0.01]);
        self::assertTrue($client->capture(['distinctId' => 'some-user', 'event' => 'short timeout']));
        self::assertSame('short timeout', $this->flushEvent($client)['event']);
    }

    public function testBatchSizeOneConnectionErrorReturnsFalse(): void
    {
        $this->server->stop();
        $client = $this->client(['batch_size' => 1, 'debug' => false, 'timeout' => 0.01]);
        self::assertFalse($client->capture(['distinctId' => 'some-user', 'event' => 'connection refused']));
    }

    public function testProductionProblems(): void
    {
        $this->server->stop();
        $client = $this->client(['debug' => false, 'timeout' => 0.01]);
        self::assertTrue($client->capture(['distinctId' => 'some-user', 'event' => 'connection refused']));
        self::assertFalse($client->flush());
        self::assertTrue($client->flush(), 'Non-retryable socket failures drop the failed batch');
    }

    public function testLargeMessage(): void
    {
        $client = $this->client();
        $largeProperty = str_repeat('a', 10000);
        self::assertTrue($client->capture([
            'distinctId' => 'some-user',
            'event' => 'large event',
            'properties' => ['big_property' => $largeProperty],
        ]));
        self::assertSame($largeProperty, $this->flushEvent($client)['properties']['big_property']);
    }

    public function testHttpFailureDropsBatchAndLogsWhenDebugging(): void
    {
        $this->server->stop();
        $this->server = new LocalHttpServer([['status' => 400, 'body' => 'invalid payload']]);
        global $errorMessages;
        $errorMessages = [];
        $client = $this->client();
        self::assertTrue($client->capture(['distinctId' => 'some-user', 'event' => 'rejected']));
        self::assertFalse($client->flush());
        self::assertTrue($client->flush());
        self::assertCount(1, $this->server->requests());
        self::assertSame(['[PostHog][Socket] invalid payload'], $errorMessages);
    }

    public function testConnectionError(): void
    {
        $this->server->stop();
        $client = $this->client([
            'debug' => false,
            'timeout' => 0.01,
            'error_handler' => static function ($errno, $message): void {
                throw new RuntimeException('socket connection failed', (int) $errno);
            },
        ]);
        self::assertTrue($client->capture(['distinctId' => 'some-user', 'event' => 'connection refused']));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('socket connection failed');
        $client->flush();
    }
}
