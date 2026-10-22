<?php

namespace PostHog\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PostHog\Client;

class NonFinitePropertiesTest extends TestCase
{
    public static function nonFiniteValues(): array
    {
        return [
            'batch NaN' => ['lib_curl', NAN],
            'batch positive infinity' => ['lib_curl', INF],
            'batch negative infinity' => ['lib_curl', -INF],
            'file NaN' => ['file', NAN],
            'file positive infinity' => ['file', INF],
            'file negative infinity' => ['file', -INF],
        ];
    }

    #[DataProvider('nonFiniteValues')]
    public function testCaptureSerializesNonFinitePropertiesAsNull(string $consumer, float $number): void
    {
        $filename = tempnam(sys_get_temp_dir(), 'posthog-nonfinite-');
        $httpClient = new MockedHttpClient('unused.invalid');
        $client = new Client('test-key', [
            'consumer' => $consumer,
            'filename' => $filename,
            'batch_size' => 10,
            'flush_interval_seconds' => 3600,
        ], $httpClient);
        $object = (object) ['number' => $number, 'finite' => 4.5];
        $typedObject = new class ($number) {
            private float $hidden = INF;

            public function __construct(public readonly float $number)
            {
            }
        };
        $serializable = new class ($object) implements \JsonSerializable {
            public function __construct(private object $value)
            {
            }

            public function jsonSerialize(): mixed
            {
                return ['visible' => $this->value];
            }
        };
        $properties = [
            'number' => $number,
            'nested' => ['number' => $number, 'object' => $object],
            'items' => [1, $number, $object, 3],
            'finite' => 2.5,
            'zero' => 0,
            'enabled' => false,
            'nullable' => null,
            'strings' => ['NaN', 'Infinity', '-Infinity'],
            'typed' => $typedObject,
            'serializable' => $serializable,
        ];
        try {
            self::assertTrue($client->capture([
                'distinctId' => 'user', 'event' => 'non-finite', 'properties' => $properties,
            ]));
            self::assertTrue($client->capture([
                'distinctId' => 'user', 'event' => 'neighbor', 'properties' => ['finite' => 7],
            ]));
            self::assertTrue($client->flush());
            if ($consumer === 'file') {
                self::assertNull($httpClient->calls);
                $events = array_map(
                    static fn(string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
                    file($filename, FILE_IGNORE_NEW_LINES)
                );
            } else {
                self::assertCount(1, $httpClient->calls);
                self::assertSame('/batch/', $httpClient->calls[0]['path']);
                $payload = json_decode($httpClient->calls[0]['payload'], true, 512, JSON_THROW_ON_ERROR);
                self::assertSame('test-key', $payload['api_key']);
                $events = $payload['batch'];
            }
            self::assertSame(['non-finite', 'neighbor'], array_column($events, 'event'));
            self::assertSame([
                'number' => null,
                'nested' => ['number' => null, 'object' => ['number' => null, 'finite' => 4.5]],
                'items' => [1, null, ['number' => null, 'finite' => 4.5], 3],
                'finite' => 2.5,
                'zero' => 0,
                'enabled' => false,
                'nullable' => null,
                'strings' => ['NaN', 'Infinity', '-Infinity'],
                'typed' => ['number' => null],
                'serializable' => ['visible' => ['number' => null, 'finite' => 4.5]],
            ], array_intersect_key($events[0]['properties'], $properties));
            self::assertSame(7, $events[1]['properties']['finite']);
            self::assertSame($object, $properties['nested']['object']);
            self::assertSame($object, $properties['items'][2]);
            if (is_nan($number)) {
                self::assertNan($properties['number']);
                self::assertNan($properties['nested']['number']);
                self::assertNan($properties['items'][1]);
                self::assertNan($object->number);
                self::assertNan($typedObject->number);
            } else {
                self::assertSame($number, $properties['number']);
                self::assertSame($number, $properties['nested']['number']);
                self::assertSame($number, $properties['items'][1]);
                self::assertSame($number, $object->number);
                self::assertSame($number, $typedObject->number);
            }
        } finally {
            $client->shutdown();
            unlink($filename);
        }
    }
}
