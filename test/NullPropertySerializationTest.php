<?php

namespace PostHog\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PostHog\Client;
use PostHog\Consumer\File;
use PostHog\HttpClient;
use PostHog\HttpResponse;

class NullPropertySerializationTest extends TestCase
{
    public static function modes(): array
    {
        return [[1, false], [100, false], [1, true], [100, true]];
    }

    private static function properties(): array
    {
        return [
            'test' => null,
            'nested' => ['drop' => null],
            'items' => ['1', null, 2, ['drop' => null], [null]],
            'empty' => '', 'zero' => 0, 'enabled' => false,
            'literal' => 'null', 'literalUndefined' => 'undefined',
            'emptyArray' => [], 'emptyObject' => (object) [],
            'object' => (object) ['drop' => null],
            'serialized' => new NullableJsonValue(['drop' => null]),
            'serializedNull' => new NullableJsonValue(null),
            'serializedList' => new NullableJsonValue([null, ['drop' => null]]),
            'numericObject' => [1 => null, 2 => 'keep'],
            'maxInteger' => PHP_INT_MAX, 'float' => 1.2345678901234567,
            'negativeZero' => -0.0,
            '$set' => ['drop' => null], '$group_set' => ['drop' => null],
            '$ai_input' => [['content' => null]],
        ];
    }

    private function assertProperties($properties): void
    {
        $expected = json_decode(json_encode(self::properties()));
        unset($expected->test, $expected->serializedNull);
        foreach (['nested', 'object', 'serialized', '$set', '$group_set'] as $key) {
            $expected->$key = (object) [];
        }
        $expected->items[3] = (object) [];
        $expected->serializedList[1] = (object) [];
        $expected->numericObject = (object) ['2' => 'keep'];
        $expected->{'$ai_input'} = [(object) []];
        foreach (get_object_vars($expected) as $key => $value) {
            $this->assertEquals($value, $properties->$key, $key);
            $this->assertSame(json_encode($value), json_encode($properties->$key), $key);
        }
        $this->assertObjectNotHasProperty('test', $properties);
        $this->assertObjectNotHasProperty('serializedNull', $properties);
        $this->assertObjectNotHasProperty('missing', $properties);
    }

    #[DataProvider('modes')]
    public function testLibCurlWireAfterHooksAndRaw(int $batchSize, bool $compressed): void
    {
        $http = $this->createMock(HttpClient::class);
        $bodies = [];
        $http->method('sendRequest')->willReturnCallback(
            function ($path, $body) use (&$bodies, $compressed) {
                $this->assertSame('/batch/', $path);
                $bodies[] = json_decode($compressed ? gzdecode($body) : $body);
                return new HttpResponse('{}', 200);
            }
        );
        $client = new Client('fake-key', [
            'consumer' => 'lib_curl',
            'host' => 'http://127.0.0.1:1', 'batch_size' => $batchSize,
            'compress_request' => $compressed,
            'before_send' => static function ($event) {
                if ($event['event'] === 'drop') {
                    return null;
                }
                $event['properties']['hookNull'] = null;
                $event['properties']['hookItems'] = [null, ['drop' => null]];
                return $event;
            },
        ], $http);
        $properties = self::properties();
        $original = json_encode($properties);
        $this->assertTrue($client->capture([
            'event' => 'capture', 'distinctId' => 'user', 'properties' => $properties,
        ]));
        $this->assertTrue($client->captureException('test exception', 'user', $properties));
        $this->assertTrue($client->identify(['distinctId' => 'user', 'properties' => $properties]));
        $client->capture(['event' => 'drop', 'distinctId' => 'user']);
        $this->assertTrue($client->raw(['event' => '$exception', 'timestamp' => null, 'properties' => [
            'test' => null, '$exception_list' => [['value' => null]],
        ]]));
        $this->assertTrue($client->raw(['event' => 'only-null', 'properties' => ['test' => null]]));
        $this->assertTrue($client->capture([
            'event' => 'only-null-capture', 'distinctId' => 'user', 'properties' => ['test' => null],
        ]));
        $this->assertTrue($client->flush());
        $events = array_merge(...array_map(static fn ($body) => $body->batch, $bodies));
        $this->assertCount(6, $events);
        foreach (array_slice($events, 0, 3) as $event) {
            $this->assertProperties($event->properties);
            $this->assertObjectNotHasProperty('hookNull', $event->properties);
            $this->assertSame('[null,{}]', json_encode($event->properties->hookItems));
        }
        $this->assertProperties($events[2]->{'$set'});
        $this->assertNull($events[3]->timestamp);
        $this->assertSame('[{"value":null}]', json_encode($events[3]->properties->{'$exception_list'}));
        $this->assertSame('{}', json_encode($events[4]->properties));
        $this->assertObjectNotHasProperty('test', $events[5]->properties);
        $this->assertSame('posthog-php', $events[5]->properties->{'$lib'});
        $this->assertSame($original, json_encode($properties));
    }

    #[DataProvider('modes')]
    public function testGeneratedMissingAndErrorFlagMetadata(int $batchSize, bool $compressed): void
    {
        $path = tempnam(__DIR__, 'null-flags-');
        try {
            foreach (['lib_curl', 'file'] as $consumer) {
                $bodies = [];
                $http = $this->createMock(HttpClient::class);
                $http->method('sendRequest')->willReturnCallback(
                    function ($url, $body) use (&$bodies, $compressed) {
                        if (str_starts_with($url, '/flags/?')) {
                            return new HttpResponse('{"flags":{},"errorsWhileComputingFlags":true}', 200);
                        }
                        $this->assertSame('/batch/', $url);
                        $bodies[] = json_decode($compressed ? gzdecode($body) : $body, true);
                        return new HttpResponse('{}', 200);
                    }
                );
                $client = new Client('fake-key', [
                    'consumer' => $consumer, 'filename' => $path, 'host' => 'http://127.0.0.1:1',
                    'batch_size' => $batchSize, 'compress_request' => $compressed,
                    'before_send' => static function ($event) {
                        $event['properties']['custom'] = ['drop' => null];
                        $event['properties']['$feature/unrelated'] = null;
                        return $event;
                    },
                ], $http);
                $this->assertNull($client->evaluateFlags('snapshot-user')->getFlag('missing'));
                $this->assertNull($client->getFeatureFlag('missing', 'legacy-user'));
                $client->capture(['event' => 'ordinary', 'distinctId' => 'user', 'properties' => [
                    '$feature_flag' => 'missing', '$feature_flag_response' => null,
                ]]);
                $client->flush();
                unset($client);
                $events = $consumer === 'file'
                    ? array_map(static fn ($line) => json_decode($line, true), file($path, FILE_IGNORE_NEW_LINES))
                    : array_merge(...array_column($bodies, 'batch'));
                $this->assertCount(3, $events);
                foreach (array_slice($events, 0, 2) as $event) {
                    $this->assertSame('$feature_flag_called', $event['event']);
                    $this->assertArrayHasKey('$feature_flag_response', $event['properties']);
                    $this->assertNull($event['properties']['$feature_flag_response']);
                    $this->assertStringContainsString('flag_missing', $event['properties']['$feature_flag_error']);
                    $this->assertSame([], $event['properties']['custom']);
                    $this->assertArrayNotHasKey('$feature/unrelated', $event['properties']);
                }
                $this->assertArrayNotHasKey('$feature_flag_response', $events[2]['properties']);
            }
        } finally {
            unlink($path);
        }
    }

    #[DataProvider('modes')]
    public function testNulKeysDoNotDestroyBatch(int $batchSize, bool $compressed): void
    {
        $http = $this->createMock(HttpClient::class);
        $bodies = [];
        $http->method('sendRequest')->willReturnCallback(function ($url, $body) use (&$bodies, $compressed) {
            $this->assertSame('/batch/', $url);
            $bodies[] = json_decode($compressed ? gzdecode($body) : $body, true);
            return new HttpResponse('{}', 200);
        });
        $client = new Client('fake-key', [
            'host' => 'http://127.0.0.1:1', 'batch_size' => $batchSize, 'compress_request' => $compressed,
        ], $http);
        $value = new NullableJsonValue(["\0custom" => 1, "\0drop" => null]);
        $properties = [
            "\0custom" => 1, "\0drop" => null, 'nested' => $value,
            'items' => [null, ["\0drop" => null]],
            '_prefix' => "\0value", 'a"b\\c雪' => 2, "mid\0nul" => 3,
            '' => 4, 'numberObject' => (object) ['0' => null, '1' => 'keep'],
        ];
        $client->capture(['event' => 'nul', 'distinctId' => 'user', 'properties' => $properties]);
        $client->capture(['event' => 'companion', 'distinctId' => 'user', 'properties' => ['keep' => true]]);
        $client->flush();
        $events = array_merge(...array_column($bodies, 'batch'));
        $this->assertCount(2, $events);
        $wire = $events[0]['properties'];
        $this->assertSame(1, $wire["\0custom"]);
        $this->assertArrayNotHasKey("\0drop", $wire);
        $this->assertSame(["\0custom" => 1], $wire['nested']);
        $this->assertSame([null, []], $wire['items']);
        foreach (['_prefix', 'a"b\\c雪', "mid\0nul", ''] as $key) {
            $this->assertSame($properties[$key], $wire[$key]);
        }
        $this->assertTrue($events[1]['properties']['keep']);
        $this->assertSame(1, $value->calls);
        $this->assertArrayHasKey("\0drop", $properties);
    }

    public function testKeyTransformResourceFailureUsesExistingErrorHandling(): void
    {
        $path = tempnam(__DIR__, 'null-failure-');
        $messages = [];
        $options = ['filename' => $path, 'host' => 'http://127.0.0.1:1',
            'error_handler' => static function ($code, $message) use (&$messages) {
                $messages[] = $message;
            }];
        $http = $this->createMock(HttpClient::class);
        $http->expects($this->never())->method('sendRequest');
        $file = new File('fake-key', $options);
        $queue = new \PostHog\Consumer\LibCurl('fake-key', $options, $http);
        $event = ['event' => 'failure', 'properties' => ['drop' => null]];
        $limit = ini_get('pcre.backtrack_limit');
        try {
            ini_set('pcre.backtrack_limit', '0');
            $encoded = \PostHog\EventSerializer::encode($event, false, $encodeError);
            $decoded = \PostHog\EventSerializer::decode('{"properties":{"drop":null}}', $decodeError);
            $written = $file->capture($event);
            $sent = $queue->flushBatch([$event]);
        } finally {
            ini_set('pcre.backtrack_limit', $limit);
            unset($file, $queue);
        }
        try {
            $this->assertFalse($encoded);
            $this->assertNull($decoded);
            $this->assertStringContainsString('JSON key transform failed:', $encodeError);
            $this->assertSame($encodeError, $decodeError);
            $this->assertFalse($written);
            $this->assertSame('non_retryable_failure', $sent);
            $this->assertSame('', file_get_contents($path));
            $this->assertCount(2, $messages);
            foreach ($messages as $message) {
                $this->assertStringContainsString('JSON key transform failed:', $message);
            }
            $this->assertIsString(\PostHog\EventSerializer::encode($event, false, $encodeError));
            $this->assertNull($encodeError);
        } finally {
            unlink($path);
        }
    }

    public function testKeyDecodingCompatibilityControls(): void
    {
        foreach (['a', '\\"'] as $character) {
            $event = ['event' => 'long', 'properties' => ['value' => str_repeat($character, 30_000)]];
            $this->assertSame(json_encode($event), \PostHog\EventSerializer::encode($event));
        }
        $json = <<<'JSON'
        {"properties":{"\u0000keep":1,"\u0000drop":null,"_":2,"":3,
        "value":"\u0000keep: \"_key\": null","numeric":{"0":"keep"},"empty":{}}}
        JSON;
        $decoded = \PostHog\EventSerializer::decode($json);
        $this->assertSame(json_decode($json, true), json_decode(json_encode($decoded), true));
        $this->assertStringContainsString('"numeric":{"0":"keep"}', json_encode($decoded));
        $this->assertStringContainsString('"empty":{}', json_encode($decoded));
        $this->assertSame(
            '{"cache":{"\\u0000keep":1,"drop":null},"timestamp":null}',
            \PostHog\EventSerializer::encode(['cache' => ["\0keep" => 1, 'drop' => null], 'timestamp' => null])
        );
        foreach (['{', '{"x":01}', '{"x":"\\q"}', "{\"x\":\"\xFF\"}"] as $invalid) {
            json_decode($invalid);
            $error = json_last_error();
            $this->assertNull(\PostHog\EventSerializer::decode($invalid));
            $this->assertSame($error, json_last_error());
        }
        $deep = null;
        for ($i = 0; $i < 513; $i++) {
            $deep = [$deep];
        }
        $this->assertFalse(\PostHog\EventSerializer::encode(['properties' => $deep]));
        $this->assertSame(JSON_ERROR_DEPTH, json_last_error());
        $this->assertFalse(\PostHog\EventSerializer::encode(['properties' => ['infinite' => INF]]));
        $this->assertSame(JSON_ERROR_INF_OR_NAN, json_last_error());
    }

    public function testJsonCompatibilityControls(): void
    {
        $event = ['event' => 'numbers', 'properties' => [
            'max' => PHP_INT_MAX, 'min' => PHP_INT_MIN, 'negativeZero' => -0.0,
            'largeFloat' => 1.2345678901234567e100, 'smallFloat' => 5.0e-324,
        ]];
        $this->assertSame(json_encode($event), \PostHog\EventSerializer::encode($event));
        $cycle = new \stdClass();
        $cycle->self = $cycle;
        $this->assertFalse(\PostHog\EventSerializer::encode(['properties' => $cycle]));
        $this->assertSame(JSON_ERROR_RECURSION, json_last_error());
        $this->assertFalse(\PostHog\EventSerializer::encode(['properties' => ['invalid' => "\xB1\x31"]]));
        $this->assertSame(JSON_ERROR_UTF8, json_last_error());
        $this->assertSame('{"properties":null}', \PostHog\EventSerializer::encode(['properties' => null]));
        $this->assertSame(
            '{"event":"custom","properties":{"$exception_list":[{}]}}',
            \PostHog\EventSerializer::encode(['event' => 'custom', 'properties' => [
                '$exception_list' => [['drop' => null]],
            ]])
        );
    }

    public function testFilePersistsObjectsAndRestoresAnArrayEnvelope(): void
    {
        $path = tempnam(__DIR__, 'null-events-');
        try {
            $consumer = new File('fake-key', ['filename' => $path]);
            $this->assertTrue($consumer->capture(['event' => 'disk', 'properties' => self::properties()]));
            unset($consumer);
            $event = json_decode(trim(file_get_contents($path)));
            $this->assertProperties($event->properties);
            // Use the same object-preserving decoder as send.php.
            $restored = \PostHog\EventSerializer::decode(trim(file_get_contents($path)));
            $http = $this->createMock(HttpClient::class);
            $http->expects($this->once())->method('sendRequest')->willReturnCallback(function ($path, $body) {
                $this->assertProperties(json_decode($body)->batch[0]->properties);
                return new HttpResponse('{}', 200);
            });
            $client = new Client('fake-key', ['host' => 'http://127.0.0.1:1'], $http);
            $this->assertTrue($client->raw($restored));
            $this->assertTrue($client->flush());
        } finally {
            unlink($path);
        }
    }
}
