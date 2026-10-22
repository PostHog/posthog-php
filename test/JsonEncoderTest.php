<?php

namespace PostHog\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PostHog\JsonEncoder;

class JsonEncoderTest extends TestCase
{
    public function testSupportedObjectRepresentationsAndReferencesArePreserved(): void
    {
        $number = INF;
        $input = [
            'reference' => &$number,
            'date' => new \DateTimeImmutable('2024-01-01T00:00:00Z'),
            'arrayObject' => new \ArrayObject(['number' => NAN]),
            'dateSubclass' => new class ('2024-01-01T00:00:00Z') extends \DateTimeImmutable {
                public string $label = 'kept';
                private float $hidden = INF;
            },
            'arrayObjectSubclass' => new class (['number' => INF]) extends \ArrayObject {
                public string $ignored = 'ignored';
            },
            'emptyObject' => new \stdClass(),
            'emptyArray' => [],
            'sparseArray' => [2 => INF, 4 => 'kept'],
        ];
        self::assertSame(
            '{"reference":null,"date":{"date":"2024-01-01 00:00:00.000000","timezone_type":2,"timezone":"Z"}'
            . ',"arrayObject":{"number":null}'
            . ',"dateSubclass":{"label":"kept","date":"2024-01-01 00:00:00.000000","timezone_type":2,"timezone":"Z"}'
            . ',"arrayObjectSubclass":{"number":null},"emptyObject":{},"emptyArray":[]'
            . ',"sparseArray":{"2":null,"4":"kept"}}',
            JsonEncoder::encode($input)
        );
        self::assertSame(INF, $number);
    }

    public function testPreservesPublicPropertyNamesContainingEmbeddedNullBytes(): void
    {
        $object = (object) ["a\0b" => 1];
        $expected = '{"a\u0000b":1}';

        self::assertSame($expected, json_encode($object));
        self::assertSame($expected, JsonEncoder::encode($object));
        self::assertSame(1, $object->{"a\0b"});
    }

    public static function propertyHookParents(): array
    {
        return [
            'ordinary class' => [''],
            'stdClass subclass' => [' extends \\stdClass'],
        ];
    }

    #[DataProvider('propertyHookParents')]
    public function testPropertyHooksControlTheSerializedPublicView(string $parentDeclaration): void
    {
        if (PHP_VERSION_ID < 80400) {
            self::markTestSkipped('Property hooks require PHP 8.4.');
        }
        $object = eval(sprintf(<<<'PHP'
        return new class%s {
            public string $value = 'private fixture' {
                get => $this->value === '' ? '' : 'filtered';
            }
            public string $virtual {
                get => 'visible';
            }
        };
        PHP, $parentDeclaration));
        $native = json_decode(json_encode($object), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($native === ['value' => 'filtered', 'virtual' => 'visible']);
        $encoded = json_decode(
            JsonEncoder::encode(['number' => NAN, 'object' => $object]),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertTrue(
            $encoded['object'] === ['value' => 'filtered', 'virtual' => 'visible'],
            'Serialization must use the public property getters, not backing storage.'
        );
        self::assertNull($encoded['number']);
    }

    #[DataProvider('propertyHookParents')]
    public function testPropertyGetterExceptionsAreNotSwallowed(string $parentDeclaration): void
    {
        if (PHP_VERSION_ID < 80400) {
            self::markTestSkipped('Property hooks require PHP 8.4.');
        }
        $object = eval(sprintf(<<<'PHP'
        return new class%s {
            public string $value {
                get => throw new LogicException('unsupported getter');
            }
        };
        PHP, $parentDeclaration));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('unsupported getter');
        JsonEncoder::encode($object);
    }

    public function testUnsupportedValuesStillFailEncoding(): void
    {
        $resource = fopen('php://memory', 'r');
        try {
            self::assertFalse(JsonEncoder::encode(['number' => NAN, 'unsupported' => $resource]));
            self::assertSame(JSON_ERROR_UNSUPPORTED_TYPE, json_last_error());
            self::assertFalse(JsonEncoder::encode(['number' => INF, 'invalid' => "\xB1\x31"]));
            self::assertSame(JSON_ERROR_UTF8, json_last_error());
        } finally {
            fclose($resource);
        }
    }

    public function testJsonSerializableExceptionsAreNotSwallowed(): void
    {
        $value = new class implements \JsonSerializable {
            public function jsonSerialize(): mixed
            {
                throw new \LogicException('unsupported serialization');
            }
        };
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('unsupported serialization');
        JsonEncoder::encode($value);
    }

    public function testRecursiveAndOverDepthInputFailsInBoundedSubprocess(): void
    {
        $code = <<<'PHP'
        require $argv[1];
        $array = [];
        $array['self'] = &$array;
        $array['again'] = &$array;
        $object = new stdClass();
        $object->self = $object;
        $first = new class implements JsonSerializable {
            public object $next;

            public function jsonSerialize(): mixed
            {
                return $this->next;
            }
        };
        $second = clone $first;
        $first->next = $second;
        $second->next = $first;
        if (json_encode($first) !== false || json_last_error() !== JSON_ERROR_RECURSION) {
            exit(2);
        }
        if (PostHog\JsonEncoder::encode($first) !== false || json_last_error() !== JSON_ERROR_RECURSION) {
            exit(3);
        }
        $wideObject = new stdClass();
        $wideArray = [];
        for ($i = 0; $i < 10000; ++$i) {
            $wideObject->{'p' . $i} = $i;
            $wideArray['p' . $i] = $i;
        }
        $wideObject->self = $wideObject;
        $wideArray['self'] = &$wideArray;
        foreach ([$wideObject, $wideArray] as $value) {
            if (PostHog\JsonEncoder::encode($value) !== false || json_last_error() !== JSON_ERROR_RECURSION) {
                exit(4);
            }
        }
        $deep = ['number' => INF];
        for ($i = 0; $i < 513; ++$i) {
            $deep = [$deep];
        }
        foreach ([$array, $object, $deep] as $value) {
            if (PostHog\JsonEncoder::encode($value) !== false) {
                exit(1);
            }
        }
        echo 'rejected';
        PHP;
        $process = proc_open(
            [PHP_BINARY, '-d', 'memory_limit=32M', '-d', 'max_execution_time=3', '-r', $code,
                __DIR__ . '/../vendor/autoload.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $errors);
        self::assertSame('rejected', $output);
    }
}
