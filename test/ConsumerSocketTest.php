<?php

namespace PostHog\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class ConsumerSocketTest extends ConsumerTransportTestCase
{
    use HttpRetryTransportTests;

    protected function consumer(): string
    {
        return 'socket';
    }

    public static function incompleteResponseCases(): array
    {
        return [
            'missing header terminator' => ["HTTP/1.1 200 OK\r\nContent-Length: 0\r\n", 0.1],
            'oversized unterminated header' => ["HTTP/1.1 200 OK\r\nX-Padding: " . str_repeat('a', 70 * 1024), 6.0],
        ];
    }

    #[DataProvider('incompleteResponseCases')]
    public function testIncompleteResponseKeepsBatchForRetry(string $response, float $timeout): void
    {
        $this->server->stop();
        $this->server = new LocalHttpServer([['raw_response' => $response]]);
        $client = $this->client(['timeout' => $timeout]);
        $previousTimeout = ini_set('default_socket_timeout', '6');

        try {
            self::assertTrue($client->capture(['distinctId' => 'some-user', 'event' => 'incomplete response']));
            self::assertFalse($client->flush());
            self::assertCount(1, $this->server->requests());
            self::assertTrue($client->flush());
            [$failed, $resent] = $this->server->requests();
            self::assertSame($failed['body'], $resent['body']);
        } finally {
            ini_set('default_socket_timeout', $previousTimeout);
        }
    }

    public static function defaultReadTimeouts(): array
    {
        return ['bounded default' => ['60'], 'unlimited default' => ['-1']];
    }

    #[DataProvider('defaultReadTimeouts')]
    public function testShortTimeout(string $defaultTimeout): void
    {
        $previousTimeout = ini_set('default_socket_timeout', $defaultTimeout);
        try {
            $client = $this->client(['timeout' => 0.01]);
            self::assertTrue($client->capture(['distinctId' => 'some-user', 'event' => 'short timeout']));
            self::assertSame('short timeout', $this->flushEvent($client)['event']);
        } finally {
            ini_set('default_socket_timeout', $previousTimeout);
        }
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
