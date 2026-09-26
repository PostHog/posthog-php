<?php

namespace PostHog\Test;

use PHPUnit\Framework\TestCase;
use PostHog\Client;
use PostHog\PostHog;

class ConsumerFileTest extends TestCase
{
    private Client $client;
    private string $filename;

    public function setUp(): void
    {
        $this->filename = tempnam(sys_get_temp_dir(), 'posthog-file-test-');
        $this->client = new Client('test-key', ['consumer' => 'file', 'filename' => $this->filename]);
    }

    public function tearDown(): void
    {
        $this->client->shutdown();
        if (file_exists($this->filename)) {
            unlink($this->filename);
        }
    }

    public function testCapture(): void
    {
        self::assertTrue($this->client->capture([
            'distinctId' => 'some-user',
            'event' => 'File PHP Event - Microtime',
            'timestamp' => 1704067200,
        ]));
        $event = $this->writtenEvent('File PHP Event - Microtime');
        self::assertSame('some-user', $event['distinct_id']);
        self::assertSame('2024-01-01T00:00:00+00:00', $event['timestamp']);
    }

    public function testIdentify(): void
    {
        self::assertTrue($this->client->identify([
            'distinctId' => 'Calvin',
            'properties' => ['loves_php' => false, 'birthday' => 1704067200],
        ]));
        $event = $this->writtenEvent('$identify');
        self::assertSame('Calvin', $event['distinct_id']);
        self::assertFalse($event['properties']['loves_php']);
        self::assertSame(1704067200, $event['properties']['birthday']);
    }

    public function testAlias(): void
    {
        self::assertTrue($this->client->alias(['alias' => 'previous-id', 'distinctId' => 'user-id']));
        $event = $this->writtenEvent('$create_alias');
        self::assertSame('previous-id', $event['properties']['alias']);
        self::assertSame('user-id', $event['properties']['distinct_id']);
    }

    public function testSend(): void
    {
        for ($i = 0; $i < 200; ++$i) {
            self::assertTrue($this->client->capture(['distinctId' => 'distinctId', 'event' => "event-$i"]));
        }
        $this->client->shutdown();
        $server = new LocalHttpServer();
        try {
            $process = proc_open(
                [PHP_BINARY, __DIR__ . '/../send.php', '--apiKey', 'test-key', '--file', $this->filename],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                array_merge(getenv(), [PostHog::ENV_HOST => 'http://' . $server->address()])
            );
            self::assertIsResource($process);
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $errors);
            self::assertSame('sent 200 from 200 requests successfully', trim($output));
            self::assertFileDoesNotExist($this->filename);
            $requests = $server->requests();
            self::assertCount(2, $requests);
            $events = [];
            foreach ($requests as $request) {
                self::assertSame('POST /batch/ HTTP/1.1', $request['requestLine']);
                $payload = json_decode($request['body'], true, 512, JSON_THROW_ON_ERROR);
                self::assertSame('test-key', $payload['api_key']);
                self::assertCount(100, $payload['batch']);
                $events = array_merge($events, $payload['batch']);
            }
            self::assertSame(
                array_map(static fn(int $i): string => "event-$i", range(0, 199)),
                array_column($events, 'event')
            );
            self::assertSame(array_fill(0, 200, 'distinctId'), array_column($events, 'distinct_id'));
        } finally {
            $server->stop();
        }
    }

    public function testProductionProblems(): void
    {
        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = [$severity, $message];
            return true;
        }, E_WARNING);
        try {
            $client = new Client('test-key', [
                'consumer' => 'file',
                'filename' => $this->filename . '/not-a-directory',
            ]);
        } finally {
            restore_error_handler();
        }
        self::assertCount(2, $warnings);
        self::assertSame(E_WARNING, $warnings[0][0]);
        self::assertStringContainsString('fopen(', $warnings[0][1]);
        self::assertStringContainsString('chmod(', $warnings[1][1]);
        self::assertFalse($client->capture(['distinctId' => 'some-user', 'event' => 'my event']));
    }

    private function writtenEvent(string $name): array
    {
        $lines = file($this->filename, FILE_IGNORE_NEW_LINES);
        self::assertCount(1, $lines);
        $event = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('type', $event);
        self::assertSame($name, $event['event']);
        return $event;
    }
}
