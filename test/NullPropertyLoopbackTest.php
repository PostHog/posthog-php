<?php

namespace PostHog\Test;

use PHPUnit\Framework\TestCase;
use PostHog\Client;

class NullPropertyLoopbackTest extends TestCase
{
    private string $directory;
    private string $host;
    private array $environment;
    private $server;

    protected function setUp(): void
    {
        $this->directory = __DIR__ . '/null-loopback-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $this->host = 'http://' . $address;
        $this->environment = array_merge(getenv(), [
            'POSTHOG_TEST_HOST' => $this->host,
            'POSTHOG_TEST_BODIES' => $this->directory . '/bodies.ndjson',
            'TMPDIR' => $this->directory,
            'http_proxy' => '', 'https_proxy' => '', 'all_proxy' => '',
            'HTTP_PROXY' => '', 'HTTPS_PROXY' => '', 'ALL_PROXY' => '',
            'NO_PROXY' => '*', 'no_proxy' => '*',
        ]);
        unset($this->environment['PHP_CLI_SERVER_WORKERS']);
        $this->server = proc_open(
            [PHP_BINARY, '-S', $address, __DIR__ . '/fixtures/null-property-loopback-router.php'],
            $this->descriptors(),
            $pipes,
            dirname(__DIR__),
            $this->environment
        );
        $this->assertIsResource($this->server);
        $deadline = microtime(true) + 5;
        do {
            $ready = @file_get_contents($this->host . '/ready', false, stream_context_create([
                'http' => ['timeout' => 0.1],
            ]));
            if ($ready === 'ready') {
                return;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);
        $this->fail('Loopback receiver did not start');
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    private function descriptors(): array
    {
        return [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $this->directory . '/stdout', 'a'],
            2 => ['file', $this->directory . '/stderr', 'a'],
        ];
    }

    private function runPhp(array $arguments, int $expectedExit = 0): void
    {
        $command = array_merge([
            PHP_BINARY, '-d', 'auto_prepend_file=' . __DIR__ . '/fixtures/null-property-egress-guard.php',
        ], $arguments);
        $process = proc_open($command, $this->descriptors(), $pipes, dirname(__DIR__), $this->environment);
        $this->assertIsResource($process);
        try {
            $deadline = microtime(true) + 15;
            do {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $this->assertSame(
                        $expectedExit,
                        $status['exitcode'],
                        file_get_contents($this->directory . '/stderr')
                    );
                    return;
                }
                usleep(10_000);
            } while (microtime(true) < $deadline);
            $this->fail('SDK subprocess exceeded timeout');
        } finally {
            proc_terminate($process);
            proc_close($process);
        }
    }

    private function events(): array
    {
        $lines = file($this->directory . '/bodies.ndjson', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $events = [];
        foreach ($lines as $line) {
            $body = json_decode($line);
            $this->assertSame('fake-key', $body->api_key);
            array_push($events, ...$body->batch);
        }
        return $events;
    }

    public function testSenderDefaultHostIsUnchangedAndGuardDeniesEgress(): void
    {
        $path = $this->directory . '/events.ndjson';
        file_put_contents($path, '{"event":"guard-check","properties":{}}' . "\n");
        $this->runPhp(['send.php', '--apiKey', 'fake-key', '--file', $path], 255);
        $this->assertStringContainsString(
            'Non-loopback or unexpected SDK request denied: https://us.i.posthog.com/batch/',
            file_get_contents($this->directory . '/stderr')
        );
        $this->assertFileDoesNotExist($this->directory . '/bodies.ndjson');
    }

    public function testAllNetworkConsumersSendNormalizedBytes(): void
    {
        $this->runPhp([__DIR__ . '/fixtures/null-property-consumers.php']);
        $events = $this->events();
        $this->assertCount(20, $events);
        foreach ($events as $event) {
            $properties = $event->properties;
            $this->assertObjectNotHasProperty('test', $properties);
            $this->assertObjectNotHasProperty('hookNull', $properties);
            $this->assertSame('{}', json_encode($properties->nested));
            $this->assertSame('[null,{}]', json_encode($properties->items));
            $this->assertSame('[null,{}]', json_encode($properties->hookItems));
        }
    }

    public function testSenderAndCliReportKeyTransformFailureWithoutSendingOrDestroyingFile(): void
    {
        $path = $this->directory . '/events.ndjson';
        $json = '{"event":"failure","properties":{"drop":null}}' . "\n";
        file_put_contents($path, $json);
        $this->runPhp(['-d', 'pcre.backtrack_limit=0', 'send.php', '--apiKey', 'fake-key',
            '--file', $path, '--host', $this->host], 1);
        $retained = glob($this->directory . '/posthog-*.log');
        $this->assertCount(1, $retained);
        $this->assertSame($json, file_get_contents($retained[0]));
        $this->assertStringContainsString(
            'Failed to decode event payload: JSON key transform failed:',
            file_get_contents($this->directory . '/stdout')
        );
        $this->runPhp(['-d', 'pcre.backtrack_limit=0', 'bin/posthog', '--type', 'capture',
            '--apiKey', 'fake-key', '--host', $this->host, '--distinctId', 'user', '--event', 'cli',
            '--properties', '{"drop":null}'], 1);
        $this->assertStringContainsString(
            'Failed to decode properties: JSON key transform failed:',
            file_get_contents($this->directory . '/stdout')
        );
        $this->assertFileDoesNotExist($this->directory . '/bodies.ndjson');
    }

    public function testGeneratedMissingFlagFileRestoresTypedResponse(): void
    {
        $path = $this->directory . '/flags.ndjson';
        $http = $this->createMock(\PostHog\HttpClient::class);
        $http->method('sendRequest')->willReturnCallback(function ($url) {
            $this->assertStringStartsWith('/flags/?', $url);
            return new \PostHog\HttpResponse('{"flags":{},"errorsWhileComputingFlags":true}', 200);
        });
        $client = new Client('fake-key', [
            'consumer' => 'file', 'filename' => $path, 'host' => $this->host,
            'before_send' => static function ($event) {
                $event['properties']['custom'] = ['drop' => null];
                $event['properties']['$feature/unrelated'] = null;
                return $event;
            },
        ], $http);
        $this->assertNull($client->evaluateFlags('user')->getFlag('missing'));
        unset($client);
        $disk = json_decode(trim(file_get_contents($path)), true);
        $this->assertArrayHasKey('$feature_flag_response', $disk['properties']);
        $this->assertNull($disk['properties']['$feature_flag_response']);
        $this->runPhp(['send.php', '--apiKey', 'fake-key', '--file', $path, '--host', $this->host]);
        $events = $this->events();
        $this->assertCount(1, $events);
        $props = $events[0]->properties;
        $this->assertObjectHasProperty('$feature_flag_response', $props);
        $this->assertNull($props->{'$feature_flag_response'});
        $this->assertSame('errors_while_computing_flags,flag_missing', $props->{'$feature_flag_error'});
        $this->assertSame('{}', json_encode($props->custom));
        $this->assertObjectNotHasProperty('$feature/unrelated', $props);
    }

    public function testNulKeysSurviveFileSenderAndHistoricalCliIngress(): void
    {
        $path = $this->directory . '/events.ndjson';
        $properties = [
            "\0keep" => 1, "\0drop" => null, 'nested' => ["\0keep" => 2, "\0drop" => null],
            'emptied' => ["\0drop" => null], 'existing' => (object) [],
            'numeric' => ["\0drop" => null, '0' => 'keep'],
            'items' => [null, ["\0drop" => null]],
            '_prefix' => "\0value", 'a"b\\c雪' => 3, "mid\0nul" => 4,
        ];
        $client = new Client('fake-key', ['consumer' => 'file', 'filename' => $path]);
        $client->capture(['event' => 'persisted', 'distinctId' => 'user', 'properties' => $properties]);
        unset($client);
        $disk = json_decode(trim(file_get_contents($path)), true);
        $this->assertIsArray($disk);
        $this->assertSame(1, $disk['properties']["\0keep"]);
        $this->assertArrayNotHasKey("\0drop", $disk['properties']);
        // A historical file has not yet passed through the new serializer.
        file_put_contents($path, json_encode([
            'event' => 'historical', 'distinct_id' => 'user', 'properties' => $properties,
        ]) . "\n", FILE_APPEND);
        $this->runPhp(['send.php', '--apiKey', 'fake-key', '--file', $path, '--host', $this->host]);
        $this->runPhp(['bin/posthog', '--type', 'capture', '--apiKey', 'fake-key', '--host', $this->host,
            '--distinctId', 'user', '--event', 'cli', '--properties', json_encode($properties)]);
        $events = [];
        foreach (file($this->directory . '/bodies.ndjson', FILE_IGNORE_NEW_LINES) as $body) {
            array_push($events, ...json_decode($body, true)['batch']);
            $this->assertStringContainsString('"emptied":{}', $body);
            $this->assertStringContainsString('"existing":{}', $body);
            $this->assertStringContainsString('"numeric":{"0":"keep"}', $body);
            $this->assertStringContainsString('"items":[null,{}]', $body);
        }
        $this->assertCount(3, $events);
        foreach ($events as $event) {
            $wire = $event['properties'];
            $this->assertSame(1, $wire["\0keep"]);
            $this->assertSame(["\0keep" => 2], $wire['nested']);
            $this->assertArrayNotHasKey("\0drop", $wire);
            foreach (['_prefix', 'a"b\\c雪', "mid\0nul"] as $key) {
                $this->assertSame($properties[$key], $wire[$key]);
            }
        }
        $this->assertFileDoesNotExist($path);
    }

    public function testActualSenderRestoresFileObjectsAndCliPreservesObjects(): void
    {
        $path = $this->directory . '/events.ndjson';
        $client = new Client('fake-key', [
            'consumer' => 'file', 'filename' => $path,
            'before_send' => static function ($event) {
                $event['properties']['hookNull'] = null;
                $event['properties']['hookItems'] = [null, ['drop' => null]];
                return $event;
            },
        ]);
        $client->capture(['event' => 'persisted', 'distinctId' => 'user', 'properties' => [
            'nested' => ['drop' => null], 'existing' => (object) [], 'items' => [null, ['drop' => null]],
        ]]);
        unset($client);
        $disk = json_decode(trim(file_get_contents($path)));
        $this->assertSame('{}', json_encode($disk->properties->nested));
        $this->assertObjectNotHasProperty('hookNull', $disk->properties);
        $this->assertSame('[null,{}]', json_encode($disk->properties->hookItems));
        $this->runPhp(['send.php', '--apiKey', 'fake-key', '--file', $path, '--host', $this->host]);
        $events = $this->events();
        $this->assertCount(1, $events);
        $this->assertEquals($disk->properties, $events[0]->properties);
        $this->assertFileDoesNotExist($path);
        $this->runPhp(['bin/posthog', '--type', 'capture', '--apiKey', 'fake-key', '--host', $this->host,
            '--distinctId', 'user', '--event', 'cli', '--properties', '{"existing":{},"nested":{"drop":null}}']);
        $events = $this->events();
        $this->assertCount(2, $events);
        $this->assertSame('{}', json_encode($events[1]->properties->existing));
        $this->assertSame('{}', json_encode($events[1]->properties->nested));
    }
}
