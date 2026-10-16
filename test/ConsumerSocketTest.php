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
    public function testIncompleteResponseDoesNotConfirmDelivery(string $response, float $timeout): void
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
            self::assertCount(1, $this->server->requests());
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

    public static function retryCases(): array
    {
        return [
            'server error' => [503, false],
            'rate limited gzip' => [429, true],
            'moved permanently' => [301, false],
            'found' => [302, false],
            'temporary redirect' => [307, false],
            'permanent redirect' => [308, false],
            'no content' => [204, false],
        ];
    }

    #[DataProvider('retryCases')]
    public function testRetryResendsCompleteRequest(int $status, bool $compressed): void
    {
        $this->server->stop();
        $this->server = new LocalHttpServer([['status' => $status], ['status' => 200]]);
        $client = $this->client([
            'maximum_backoff_duration' => 200,
            'compress_request' => $compressed,
        ]);
        self::assertTrue($client->capture([
            'distinctId' => 'some-user',
            'event' => 'retried event',
            'properties' => ['text' => str_repeat('retry payload ', 100)],
        ]));

        $previousTimeout = ini_set('default_socket_timeout', '1');
        try {
            self::assertTrue($client->flush());
        } finally {
            ini_set('default_socket_timeout', $previousTimeout);
        }

        $requests = $this->server->requests();
        self::assertCount(2, $requests);
        self::assertSame($requests[0], $requests[1]);
        $payload = json_decode($requests[1]['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('retried event', $payload['batch'][0]['event']);
        self::assertSame(
            $compressed ? 'gzip' : null,
            $requests[1]['headers']['content-encoding'] ?? null
        );
        self::assertTrue($client->flush());
        self::assertCount(2, $this->server->requests());
    }

    public static function unexpectedResponseStatuses(): array
    {
        return [
            'moved permanently' => [301],
            'permanent redirect' => [308],
            'no content' => [204],
        ];
    }

    #[DataProvider('unexpectedResponseStatuses')]
    public function testUnexpectedResponseRetriesUntilBackoffLimit(int $status): void
    {
        $this->server->stop();
        $this->server = new LocalHttpServer(array_fill(0, 4, ['status' => $status]));
        $client = $this->client(['maximum_backoff_duration' => 200]);
        self::assertTrue($client->capture(['distinctId' => 'some-user', 'event' => 'redirected']));

        $previousTimeout = ini_set('default_socket_timeout', '1');
        try {
            self::assertFalse($client->flush());
        } finally {
            ini_set('default_socket_timeout', $previousTimeout);
        }

        self::assertCount(2, $this->server->requests());
    }

    public function testFailedReconnectReturnsFalseWithoutInvalidStreamAccess(): void
    {
        $this->server->stop();
        $this->server = new LocalHttpServer([['status' => 503, 'close_server' => true]]);
        $client = $this->client([
            'maximum_backoff_duration' => 200,
            'timeout' => 0.05,
            'debug' => false,
        ]);
        self::assertTrue($client->capture(['distinctId' => 'some-user', 'event' => 'failed retry']));
        self::assertFalse($client->flush());
        self::assertCount(1, $this->server->requests());
        self::assertTrue($client->flush());
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
