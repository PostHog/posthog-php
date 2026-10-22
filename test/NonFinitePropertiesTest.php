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

    public static function serializableEnums(): array
    {
        return [
            'NaN' => [NonFiniteJsonSerializableEnum::NotANumber],
            'positive infinity' => [NonFiniteJsonSerializableEnum::PositiveInfinity],
            'negative infinity' => [NonFiniteJsonSerializableEnum::NegativeInfinity],
        ];
    }

    #[DataProvider('serializableEnums')]
    public function testCaptureNormalizesNonFiniteJsonSerializableEnums(NonFiniteJsonSerializableEnum $enum): void
    {
        $httpClient = new MockedHttpClient('unused.invalid');
        $client = new Client('test-key', [
            'batch_size' => 10,
            'flush_interval_seconds' => 3600,
        ], $httpClient);
        $properties = ['enum' => $enum, 'enabled' => false];
        try {
            self::assertTrue($client->capture([
                'distinctId' => 'user', 'event' => 'enum-event', 'properties' => $properties,
            ]));
            self::assertTrue($client->capture([
                'distinctId' => 'user', 'event' => 'neighbor', 'properties' => ['finite' => 7],
            ]));
            self::assertTrue($client->flush());
            self::assertCount(1, $httpClient->calls);
            $payload = json_decode($httpClient->calls[0]['payload'], true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(['enum-event', 'neighbor'], array_column($payload['batch'], 'event'));
            self::assertSame(
                ['number' => null, 'finite' => 2.5, 'literal' => 'NaN'],
                $payload['batch'][0]['properties']['enum']
            );
            self::assertFalse($payload['batch'][0]['properties']['enabled']);
            self::assertSame(7, $payload['batch'][1]['properties']['finite']);
            self::assertSame(['enum' => $enum, 'enabled' => false], $properties);
        } finally {
            $client->shutdown();
        }
    }

    public function testCapturePreservesStdClassSubclassPropertyHooks(): void
    {
        if (PHP_VERSION_ID < 80400) {
            self::markTestSkipped('Property hooks require PHP 8.4.');
        }
        $object = eval(<<<'PHP'
        return new class extends stdClass {
            public string $value = 'private fixture' {
                get => $this->value === '' ? '' : 'filtered';
            }
            public string $virtual {
                get => 'visible';
            }
            public float $finite = 2.5;
        };
        PHP);
        $expected = ['value' => 'filtered', 'virtual' => 'visible', 'finite' => 2.5];
        self::assertTrue(json_decode(json_encode($object), true, 512, JSON_THROW_ON_ERROR) === $expected);
        $httpClient = new MockedHttpClient('unused.invalid');
        $client = new Client('test-key', [
            'batch_size' => 10,
            'flush_interval_seconds' => 3600,
        ], $httpClient);
        try {
            self::assertTrue($client->capture([
                'distinctId' => 'user',
                'event' => 'hooked-subclass',
                'properties' => ['object' => $object, 'number' => NAN],
            ]));
            self::assertTrue($client->capture([
                'distinctId' => 'user', 'event' => 'neighbor', 'properties' => ['finite' => 7],
            ]));
            self::assertTrue($client->flush());
            self::assertCount(1, $httpClient->calls);
            $payload = json_decode($httpClient->calls[0]['payload'], true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(['hooked-subclass', 'neighbor'], array_column($payload['batch'], 'event'));
            self::assertTrue(
                $payload['batch'][0]['properties']['object'] === $expected,
                'Wire serialization must preserve the filtered public property view.'
            );
            self::assertNull($payload['batch'][0]['properties']['number']);
            self::assertSame(7, $payload['batch'][1]['properties']['finite']);
            self::assertTrue(((array) $object)['value'] === 'private fixture');
        } finally {
            $client->shutdown();
        }
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
