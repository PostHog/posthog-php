<?php

namespace PostHog\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PostHog\Client;
use PostHog\PostHog;

abstract class ConsumerTransportTestCase extends TestCase
{
    protected LocalHttpServer $server;

    abstract protected function consumer(): string;

    protected function setUp(): void
    {
        $this->server = new LocalHttpServer();
    }

    protected function tearDown(): void
    {
        $this->server->stop();
    }

    protected function client(array $options = []): Client
    {
        return new Client('test-key', array_merge([
            'consumer' => $this->consumer(),
            'host' => $this->server->address(),
            'ssl' => false,
            'debug' => true,
            'maximum_backoff_duration' => 100,
        ], $options), null, null, false);
    }

    public static function compressionCases(): array
    {
        return ['plain JSON' => [false], 'gzip' => [true]];
    }

    #[DataProvider('compressionCases')]
    public function testCapture(bool $compressed): void
    {
        $client = $this->client(['compress_request' => $compressed]);
        self::assertTrue($client->capture([
            'distinctId' => 'some-user',
            'event' => 'PHP "quoted" and apostrophe\' event',
            'properties' => ['text' => "line one\nline two café", 'enabled' => false],
        ]));
        $event = $this->flushEvent($client, $compressed);
        self::assertSame('PHP "quoted" and apostrophe\' event', $event['event']);
        self::assertSame('some-user', $event['distinct_id']);
        self::assertSame("line one\nline two café", $event['properties']['text']);
        self::assertFalse($event['properties']['enabled']);
    }

    #[DataProvider('compressionCases')]
    public function testIdentify(bool $compressed): void
    {
        $client = $this->client(['compress_request' => $compressed]);
        self::assertTrue($client->identify([
            'distinctId' => 'identified-user',
            'properties' => ['loves_php' => false, 'birthday' => 1704067200],
        ]));
        $event = $this->flushEvent($client, $compressed);
        self::assertSame('$identify', $event['event']);
        self::assertSame('identified-user', $event['distinct_id']);
        self::assertFalse($event['properties']['loves_php']);
        self::assertSame(1704067200, $event['properties']['birthday']);
    }

    #[DataProvider('compressionCases')]
    public function testAlias(bool $compressed): void
    {
        $client = $this->client(['compress_request' => $compressed]);
        self::assertTrue($client->alias(['alias' => 'previous-id', 'distinctId' => 'user-id']));
        $event = $this->flushEvent($client, $compressed);
        self::assertSame('$create_alias', $event['event']);
        self::assertSame('previous-id', $event['properties']['alias']);
        self::assertSame('user-id', $event['properties']['distinct_id']);
    }

    protected function flushEvent(Client $client, bool $compressed = false): array
    {
        self::assertSame([], $this->server->requests());
        self::assertTrue($client->flush());
        $requests = $this->server->requests();
        self::assertCount(1, $requests);
        self::assertSame('POST /batch/ HTTP/1.1', $requests[0]['requestLine']);
        self::assertSame('application/json', $requests[0]['headers']['content-type']);
        self::assertSame('posthog-php/' . PostHog::VERSION, $requests[0]['headers']['user-agent']);
        self::assertSame($compressed ? 'gzip' : null, $requests[0]['headers']['content-encoding'] ?? null);
        $payload = json_decode($requests[0]['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('test-key', $payload['api_key']);
        self::assertCount(1, $payload['batch']);
        self::assertArrayNotHasKey('type', $payload['batch'][0]);
        self::assertTrue($client->flush());
        self::assertCount(1, $this->server->requests(), 'A successful flush must drain the queue');
        return $payload['batch'][0];
    }
}
