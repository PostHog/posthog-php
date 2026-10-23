<?php

namespace PostHog\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PostHog\Internal\JsonEncoder;

class JsonEncoderReviewRegressionTest extends TestCase
{
    public function testBackedImplementationOfAbstractPropertyPreservesNativeRedactionOrder(): void
    {
        if (PHP_VERSION_ID < 80400) {
            self::markTestSkipped('Abstract properties require PHP 8.4.');
        }
        $prototype = eval(<<<'PHP'
        abstract class PostHogReviewAbstractPropertyParent {
            abstract public string $value { get; set; }
            public mixed $mutator;
        }
        return new class extends PostHogReviewAbstractPropertyParent {
            public string $value = 'secret';
        };
        PHP);
        $make = static function () use ($prototype): object {
            $object = clone $prototype;
            $object->mutator = new class ($object) implements \JsonSerializable {
                public function __construct(private object $parent)
                {
                }

                public function jsonSerialize(): mixed
                {
                    $this->parent->value = 'redacted';
                    return 'done';
                }
            };
            return $object;
        };
        $expected = '{"mutator":"done","value":"redacted"}';
        self::assertSame($expected, json_encode($make()));
        self::assertTrue(
            JsonEncoder::encode($make()) === $expected,
            'A backed implementation must not be read before an inherited redacting serializer.'
        );
    }

    public static function cyclicSerializerModes(): array
    {
        return ['stateless' => [false], 'break on second invocation' => [true]];
    }

    #[DataProvider('cyclicSerializerModes')]
    public function testActiveArrayDoesNotReenterItsSerializer(bool $breakOnSecondInvocation): void
    {
        $make = static function () use ($breakOnSecondInvocation): array {
            $properties = [];
            $calls = (object) ['count' => 0];
            $serializer = new class ($properties, $calls, $breakOnSecondInvocation) implements \JsonSerializable {
                private array $properties;

                public function __construct(
                    array &$properties,
                    private object $calls,
                    private bool $breakOnSecondInvocation
                ) {
                    $this->properties = &$properties;
                }

                public function jsonSerialize(): mixed
                {
                    ++$this->calls->count;
                    if ($this->breakOnSecondInvocation && $this->calls->count === 2) {
                        $this->properties = ['safe' => 3];
                    }
                    return 'done';
                }
            };
            $properties = ['mutator' => $serializer, 'self' => &$properties];
            return [['event' => 'cycle', 'properties' => $properties], $calls];
        };
        [$native, $nativeCalls] = $make();
        self::assertNull(\ReflectionReference::fromArrayElement($native, 'properties'));
        self::assertFalse(json_encode($native));
        self::assertSame(JSON_ERROR_RECURSION, json_last_error());
        self::assertSame(1, $nativeCalls->count);
        [$input, $calls] = $make();
        $actual = JsonEncoder::encode($input);
        $error = json_last_error();
        self::assertFalse($actual);
        self::assertSame(JSON_ERROR_RECURSION, $error);
        self::assertSame(1, $calls->count);
    }

    public function testHookedDateIntervalPreservesNativeViewAndCallerState(): void
    {
        if (PHP_VERSION_ID < 80400) {
            self::markTestSkipped('Property hooks require PHP 8.4.');
        }
        $object = eval(<<<'PHP'
        return new class ('P1D') extends DateInterval {
            public string $view {
                get => 'visible';
            }
        };
        PHP);
        $before = json_encode($object);
        self::assertSame('{"view":"visible"}', $before);
        $actual = JsonEncoder::encode($object);
        $after = json_encode($object);
        self::assertSame($before, $actual);
        self::assertSame($before, $after, 'Encoding must not materialize interval fields on the caller object.');
    }

    public function testLargeStringMatchesNativeWithin32MbBeforeBatchSizeRejection(): void
    {
        $outputs = [];
        foreach (['native', 'sdk'] as $mode) {
            $process = proc_open(
                [PHP_BINARY, '-d', 'memory_limit=32M', '-d', 'max_execution_time=10',
                    __DIR__ . '/fixtures/json-encoder-large-string.php', __DIR__ . '/../vendor/autoload.php', $mode],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            self::assertIsResource($process);
            fclose($pipes[0]);
            $outputs[$mode] = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $mode . ': ' . $errors);
        }
        self::assertSame($outputs['native'], $outputs['sdk']);
    }
}
