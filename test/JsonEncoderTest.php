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
            'arrayIterator' => new \ArrayIterator(['number' => INF]),
            'timezone' => new \DateTimeZone('UTC'),
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
            . ',"arrayObject":{"number":null},"arrayIterator":{"number":null}'
            . ',"timezone":{"timezone_type":3,"timezone":"UTC"}'
            . ',"dateSubclass":{"label":"kept","date":"2024-01-01 00:00:00.000000","timezone_type":2,"timezone":"Z"}'
            . ',"arrayObjectSubclass":{"number":null},"emptyObject":{},"emptyArray":[]'
            . ',"sparseArray":{"2":null,"4":"kept"}}',
            JsonEncoder::encode($input)
        );
        self::assertSame(INF, $number);
    }

    public static function nativeArrayFlagOverrides(): array
    {
        return [
            'ArrayObject wrong flags' => ['ArrayObject', 'return self::STD_PROP_LIST;'],
            'ArrayIterator wrong flags' => ['ArrayIterator', 'return self::STD_PROP_LIST;'],
            'ArrayObject throwing flags' => ['ArrayObject', 'throw new LogicException("unexpected override");'],
            'ArrayIterator throwing flags' => ['ArrayIterator', 'throw new LogicException("unexpected override");'],
        ];
    }

    #[DataProvider('nativeArrayFlagOverrides')]
    public function testNativeArrayFlagOverridesAreNotInvoked(string $parent, string $override): void
    {
        $object = eval(sprintf(<<<'PHP'
        return new class (['stored' => 7]) extends %s {
            public string $excluded = 'private fixture';
            private int $overrideCalls = 0;

            public function getFlags(): int
            {
                ++$this->overrideCalls;
                %s
            }

            public function overrideCalls(): int
            {
                return $this->overrideCalls;
            }
        };
        PHP, $parent, $override));
        self::assertSame('{"stored":7}', json_encode($object));
        self::assertSame(0, $object->overrideCalls());
        self::assertTrue(
            JsonEncoder::encode($object) === '{"stored":7}',
            'Native flags must preserve storage and exclude ignored public properties.'
        );
        self::assertSame(0, $object->overrideCalls());
    }

    public static function nativeArrayParents(): array
    {
        return ['ArrayObject' => ['ArrayObject'], 'ArrayIterator' => ['ArrayIterator']];
    }

    #[DataProvider('nativeArrayParents')]
    public function testNativeStandardPropertyFlagsPreserveHooksDespiteOverride(string $parent): void
    {
        if (PHP_VERSION_ID < 80400) {
            self::markTestSkipped('Property hooks require PHP 8.4.');
        }
        $object = eval(sprintf(<<<'PHP'
        return new class (['stored' => 7]) extends %s {
            public string $value = 'private fixture' {
                get => $this->value === '' ? '' : 'filtered';
            }
            public string $virtual {
                get => 'visible';
            }
            public function getFlags(): int
            {
                return 0;
            }
        };
        PHP, $parent));
        $object->setFlags(\ArrayObject::STD_PROP_LIST);
        $expected = ['value' => 'filtered', 'virtual' => 'visible'];
        self::assertTrue(json_decode(json_encode($object), true, 512, JSON_THROW_ON_ERROR) === $expected);
        self::assertTrue(
            json_decode(JsonEncoder::encode($object), true, 512, JSON_THROW_ON_ERROR) === $expected,
            'Genuine native STD_PROP_LIST must retain the getter-filtered public view.'
        );
    }

    public function testReferencedNonFiniteArraySlotDoesNotMutateCallerOrReorderList(): void
    {
        $number = INF;
        $input = ['prefix' => 1, 'items' => [0, &$number, 2], 'suffix' => false];
        self::assertSame('{"prefix":1,"items":[0,null,2],"suffix":false}', JsonEncoder::encode($input));
        self::assertSame(INF, $number);
        self::assertSame([0, INF, 2], $input['items']);
    }

    public function testCustomSerializerRunsOnceBeforeLaterObjectValuesAreRead(): void
    {
        $createInput = static function (float $next): array {
            $object = (object) ['number' => 1];
            $calls = (object) ['count' => 0];
            $serializer = new class ($object, $calls, $next) implements \JsonSerializable {
                public function __construct(private object $object, private object $calls, private float $next)
                {
                }

                public function jsonSerialize(): mixed
                {
                    ++$this->calls->count;
                    $this->object->number = $this->next;
                    return 'done';
                }
            };
            return [['mutator' => $serializer, 'object' => $object], $calls];
        };
        [$nativeInput, $nativeCalls] = $createInput(4);
        self::assertSame('{"mutator":"done","object":{"number":4}}', json_encode($nativeInput));
        self::assertSame(1, $nativeCalls->count);
        [$input, $calls] = $createInput(NAN);
        self::assertSame('{"mutator":"done","object":{"number":null}}', JsonEncoder::encode($input));
        self::assertSame(1, $calls->count);
    }

    public static function declaredPropertyChanges(): array
    {
        return [
            'ordinary class redaction' => ['', 'redacted'],
            'stdClass subclass redaction' => [' extends \\stdClass', 'redacted'],
            'Exception subclass redaction' => [' extends \\Exception', 'redacted'],
            'ordinary class non-finite update' => ['', NAN],
        ];
    }

    #[DataProvider('declaredPropertyChanges')]
    public function testDeclaredPropertyIsReadAfterEarlierSerializer(string $parent, mixed $next): void
    {
        $make = static function () use ($parent, $next): object {
            $object = eval(sprintf(<<<'PHP'
            return new class%s {
                public mixed $mutator;
                public mixed $value = 'secret';
            };
            PHP, $parent));
            $object->mutator = new class ($object, $next) implements \JsonSerializable {
                public int $calls = 0;

                public function __construct(private object $parent, private mixed $next)
                {
                }

                public function jsonSerialize(): mixed
                {
                    ++$this->calls;
                    $this->parent->value = $this->next;
                    return 'done';
                }
            };
            return $object;
        };
        $native = json_encode($make());
        if (is_float($next)) {
            self::assertFalse($native);
            self::assertSame(JSON_ERROR_INF_OR_NAN, json_last_error());
            $expected = '{"mutator":"done","value":null}';
        } else {
            $expected = '{"mutator":"done","value":"redacted"}';
            self::assertSame($expected, $native);
        }
        $object = $make();
        self::assertTrue(
            JsonEncoder::encode($object) === $expected,
            'Declared properties must be read after earlier serializers have run.'
        );
        self::assertSame(1, $object->mutator->calls);
        self::assertNull(\ReflectionReference::fromArrayElement((array) $object, 'value'));
    }

    public static function declaredPropertyPresenceChanges(): array
    {
        return [
            'remove' => [false, false],
            'remove with magic getter' => [false, true],
            'initialize' => [true, false],
            'initialize with magic getter' => [true, true],
        ];
    }

    #[DataProvider('declaredPropertyPresenceChanges')]
    public function testDeclaredPropertyPresenceMatchesNativeEncoding(bool $initialize, bool $magic): void
    {
        $make = static function () use ($initialize, $magic): object {
            $getter = $magic
                ? 'public function __get(string $name): mixed { throw new LogicException("magic read"); }'
                : '';
            $object = eval(sprintf(<<<'PHP'
            return new class {
                public mixed $mutator;
                public string $value;
                public int $after = 7;
                %s
            };
            PHP, $getter));
            if (!$initialize) {
                $object->value = 'before';
            }
            $object->mutator = new class ($object, $initialize) implements \JsonSerializable {
                public int $calls = 0;

                public function __construct(private object $parent, private bool $initialize)
                {
                }

                public function jsonSerialize(): mixed
                {
                    ++$this->calls;
                    if ($this->initialize) {
                        $this->parent->value = 'ready';
                    } else {
                        unset($this->parent->value);
                    }
                    return 'done';
                }
            };
            return $object;
        };
        $expected = $initialize
            ? '{"mutator":"done","value":"ready","after":7}'
            : '{"mutator":"done","after":7}';
        self::assertSame($expected, json_encode($make()));
        $object = $make();
        self::assertSame($expected, JsonEncoder::encode($object));
        self::assertSame(1, $object->mutator->calls);
    }

    public static function referencedScalarChanges(): array
    {
        $changes = [
            'finite to finite' => [1, 2, '2'],
            'finite to NaN' => [1, NAN, 'null'],
            'finite to positive infinity' => [1, INF, 'null'],
            'finite to negative infinity' => [1, -INF, 'null'],
            'NaN to finite' => [NAN, 2, '2'],
            'positive infinity to finite' => [INF, 2, '2'],
            'negative infinity to finite' => [-INF, 2, '2'],
        ];
        $cases = [];
        foreach (['array', 'stdClass', 'ArrayObject', 'ArrayObject public view'] as $container) {
            foreach ($changes as $label => $values) {
                $cases[$container . ' ' . $label] = [$container, ...$values];
            }
        }
        return $cases;
    }

    #[DataProvider('referencedScalarChanges')]
    public function testReferencedScalarIsReadAfterEarlierSerializer(
        string $container,
        int|float $initial,
        int|float $next,
        string $expectedNumber
    ): void {
        $encode = static function (bool $native) use ($container, $initial, $next): array {
            $number = $initial;
            $calls = 0;
            $serializer = new class ($number, $calls, $next) implements \JsonSerializable {
                private int|float $number;
                private int $calls;

                public function __construct(int|float &$number, int &$calls, private int|float $next)
                {
                    $this->number = &$number;
                    $this->calls = &$calls;
                }

                public function jsonSerialize(): mixed
                {
                    ++$this->calls;
                    $this->number = $this->next;
                    return null;
                }
            };
            $fields = ['mutator' => $serializer, 'n' => &$number, 'after' => 7];
            $input = match ($container) {
                'array' => $fields,
                'stdClass' => (object) $fields,
                'ArrayObject' => new \ArrayObject($fields),
                'ArrayObject public view' => new class extends \ArrayObject {
                    public mixed $mutator;
                    public mixed $n;
                    public int $after = 7;
                },
            };
            if ($container === 'ArrayObject public view') {
                $input->setFlags(\ArrayObject::STD_PROP_LIST);
                $input->mutator = $serializer;
                $input->n = &$number;
            }
            $before = is_array($input) ? $input : (array) $input;
            $referenceId = \ReflectionReference::fromArrayElement($before, 'n')->getId();
            $json = $native ? json_encode($input) : JsonEncoder::encode($input);
            $error = json_last_error();
            $after = is_array($input) ? $input : (array) $input;
            self::assertSame(['mutator', 'n', 'after'], array_keys($after));
            self::assertSame($referenceId, \ReflectionReference::fromArrayElement($after, 'n')->getId());
            self::assertNull(\ReflectionReference::fromArrayElement($after, 'mutator'));
            self::assertNull(\ReflectionReference::fromArrayElement($after, 'after'));
            self::assertSame(1, $calls);
            if (is_nan($next)) {
                self::assertNan($number);
            } else {
                self::assertSame($next, $number);
            }
            return [$json, $error];
        };
        [$native, $error] = $encode(true);
        if (is_finite($next)) {
            self::assertSame('{"mutator":null,"n":2,"after":7}', $native);
        } else {
            self::assertFalse($native);
            self::assertSame(JSON_ERROR_INF_OR_NAN, $error);
        }
        [$actual] = $encode(false);
        self::assertSame('{"mutator":null,"n":' . $expectedNumber . ',"after":7}', $actual);
    }

    public function testSharedReferenceIsReadAtEachWirePosition(): void
    {
        $number = 1;
        $serializer = new class ($number) implements \JsonSerializable {
            private int $number;

            public function __construct(int &$number)
            {
                $this->number = &$number;
            }

            public function jsonSerialize(): mixed
            {
                $this->number = 2;
                return null;
            }
        };
        $input = ['first' => &$number, 'mutator' => $serializer, 'last' => &$number];
        self::assertSame('{"first":1,"mutator":null,"last":2}', JsonEncoder::encode($input));
        self::assertSame(2, $number);
        self::assertSame(
            \ReflectionReference::fromArrayElement($input, 'first')->getId(),
            \ReflectionReference::fromArrayElement($input, 'last')->getId()
        );
    }

    public function testNativeSubclassSerializerRemainsDeferred(): void
    {
        $object = (object) ['number' => 1];
        $calls = (object) ['count' => 0];
        $serializer = new class ($object, $calls) extends \ArrayObject implements \JsonSerializable {
            public function __construct(private object $object, private object $calls)
            {
                parent::__construct(['stored' => 7]);
            }

            public function jsonSerialize(): mixed
            {
                ++$this->calls->count;
                $this->object->number = NAN;
                return 'done';
            }

            public function getFlags(): int
            {
                throw new \LogicException('must not dispatch');
            }
        };
        self::assertSame(
            '{"mutator":"done","object":{"number":null}}',
            JsonEncoder::encode(['mutator' => $serializer, 'object' => $object])
        );
        self::assertSame(1, $calls->count);
    }

    public function testPlainDataObjectsNormalizeNestedNumbersWithoutMutatingInput(): void
    {
        $object = (object) [
            'number' => NAN,
            'nested' => (object) ['number' => INF],
            'items' => [-INF, 0],
            'finite' => 2.5,
            'literal' => 'NaN',
        ];
        self::assertSame(
            '{"number":null,"nested":{"number":null},"items":[null,0],"finite":2.5,"literal":"NaN"}',
            JsonEncoder::encode($object)
        );
        self::assertNan($object->number);
        self::assertSame(INF, $object->nested->number);
        self::assertSame([-INF, 0], $object->items);
    }

    public function testInheritedNativePublicPropertiesKeepTheirWireOrder(): void
    {
        if (!class_exists(\PDOException::class)) {
            self::markTestSkipped('PDOException requires the PDO extension.');
        }
        $value = new class extends \PDOException {
            public string $value = 'kept';
        };
        $value->errorInfo = ['HY000'];
        $expected = '{"errorInfo":["HY000"],"value":"kept"}';
        self::assertSame($expected, json_encode($value));
        self::assertSame($expected, JsonEncoder::encode($value));
    }

    public function testNativeVirtualPropertyHandlersArePreserved(): void
    {
        if (!class_exists(\BcMath\Number::class)) {
            self::markTestSkipped('BcMath Number requires PHP 8.4 and the bcmath extension.');
        }
        $value = new \BcMath\Number('1.23');
        self::assertSame('{"value":"1.23","scale":2}', json_encode($value));
        self::assertSame(json_encode($value), JsonEncoder::encode($value));
    }

    public function testClosuresKeepTheirNativeEmptyObjectRepresentation(): void
    {
        $invoked = false;
        $callback = static function () use (&$invoked): void {
            $invoked = true;
        };
        $input = ['callback' => $callback];
        $expected = '{"callback":{}}';

        self::assertSame($expected, json_encode($input));
        self::assertSame($expected, JsonEncoder::encode($input));
        self::assertSame($callback, $input['callback']);
        self::assertFalse($invoked);
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
            'Exception subclass' => [' extends \\Exception'],
            'Error subclass' => [' extends \\Error'],
            'SplObjectStorage subclass' => [' extends \\SplObjectStorage'],
            'ArrayObject with standard properties' => [' extends \\ArrayObject'],
            'ArrayIterator with standard properties' => [' extends \\ArrayIterator'],
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
            private int $getterCalls = 0;
            public string $value = 'private fixture' {
                get {
                    ++$this->getterCalls;
                    return $this->value === '' ? '' : 'filtered';
                }
            }
            public string $virtual {
                get => 'visible';
            }
            public function getterCalls(): int
            {
                return $this->getterCalls;
            }
        };
        PHP, $parentDeclaration));
        if ($object instanceof \ArrayObject || $object instanceof \ArrayIterator) {
            $object->setFlags(\ArrayObject::STD_PROP_LIST);
        }
        $native = json_decode(json_encode($object), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($native === ['value' => 'filtered', 'virtual' => 'visible']);
        self::assertSame(1, $object->getterCalls());
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
        self::assertSame(2, $object->getterCalls());
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
        if ($object instanceof \ArrayObject || $object instanceof \ArrayIterator) {
            $object->setFlags(\ArrayObject::STD_PROP_LIST);
        }
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('unsupported getter');
        JsonEncoder::encode($object);
    }

    public function testHookedPropertyIsReadAfterEarlierSerializer(): void
    {
        if (PHP_VERSION_ID < 80400) {
            self::markTestSkipped('Property hooks require PHP 8.4.');
        }
        $make = static function (): object {
            $object = eval(<<<'PHP'
            return new class {
                public mixed $mutator;
                public string $value = 'before' {
                    get => strtoupper($this->value);
                }
            };
            PHP);
            $object->mutator = new class ($object) implements \JsonSerializable {
                public function __construct(private object $parent)
                {
                }

                public function jsonSerialize(): mixed
                {
                    $this->parent->value = 'after';
                    return 'done';
                }
            };
            return $object;
        };
        $expected = '{"mutator":"done","value":"AFTER"}';
        self::assertSame($expected, json_encode($make()));
        $object = $make();
        self::assertSame($expected, JsonEncoder::encode($object));
        self::assertSame('AFTER', $object->value);
    }

    public function testScalarEncodingDoesNotResetErrorStateSeenByCallbacks(): void
    {
        $serializer = new class implements \JsonSerializable {
            public function jsonSerialize(): mixed
            {
                return json_last_error();
            }
        };
        json_decode('{');
        $expected = json_encode(['serializer' => $serializer]);
        json_decode('{');
        self::assertSame($expected, JsonEncoder::encode(['serializer' => $serializer]));
        self::assertSame(JSON_ERROR_NONE, json_last_error());
    }

    public function testMalformedKeysRetainErrorsAndNativeCallbackOrdering(): void
    {
        $serializer = new class implements \JsonSerializable {
            public int $calls = 0;

            public function jsonSerialize(): mixed
            {
                ++$this->calls;
                return 7;
            }
        };
        self::assertFalse(json_encode(["\xB1" => $serializer]));
        self::assertSame(JSON_ERROR_UTF8, json_last_error());
        self::assertSame(1, $serializer->calls);
        self::assertFalse(JsonEncoder::encode(["\xB1" => $serializer]));
        self::assertSame(JSON_ERROR_UTF8, json_last_error());
        self::assertSame(2, $serializer->calls);
        self::assertSame('7', JsonEncoder::encode(7));
        self::assertSame(JSON_ERROR_NONE, json_last_error());
    }

    public function testDepthBoundariesMatchNativeErrors(): void
    {
        foreach ([511, 512, 513] as $depth) {
            $value = 0;
            for ($i = 0; $i < $depth; ++$i) {
                $value = [$value];
            }
            $expected = json_encode($value);
            $error = json_last_error();
            self::assertSame($expected, JsonEncoder::encode($value));
            self::assertSame($error, json_last_error());
        }
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

    public function testUserJsonExceptionsAreNotConvertedIntoEncodingFailures(): void
    {
        $value = new class implements \JsonSerializable {
            public function jsonSerialize(): mixed
            {
                throw new \JsonException('user serialization exception');
            }
        };
        $this->expectException(\JsonException::class);
        $this->expectExceptionMessage('user serialization exception');
        JsonEncoder::encode($value);
    }

    public static function siblingContainers(): array
    {
        return [
            'empty arrays' => ['empty-arrays', 300000, false],
            'shared finite scalar arrays' => ['shared-scalar-arrays', 200000, false],
            'distinct finite scalar arrays' => ['distinct-scalar-arrays', 100000, false],
            'shared empty object' => ['shared-empty-object', 300000, false],
            'distinct empty objects' => ['distinct-empty-objects', 200000, false],
            'distinct finite scalar objects' => ['distinct-scalar-objects', 50000, false],
            'dense arrays with non-finite sibling' => ['shared-scalar-arrays', 200000, true],
            'dense objects with non-finite sibling' => ['distinct-empty-objects', 200000, true],
            'dense objects with DateTime' => ['distinct-empty-objects', 200000, true, 'DateTime'],
            'dense objects with DateTimeImmutable' => ['distinct-empty-objects', 200000, true, 'DateTimeImmutable'],
            'dense objects with DateTimeZone' => ['distinct-empty-objects', 200000, true, 'DateTimeZone'],
            'dense objects with ArrayObject storage' => ['distinct-empty-objects', 200000, true, 'ArrayObject'],
            'dense objects with ArrayIterator storage' => ['distinct-empty-objects', 200000, true, 'ArrayIterator'],
            'ArrayObject public sibling' => ['distinct-empty-objects', 200000, true, 'ArrayObject-properties'],
            'ArrayIterator public sibling' => ['distinct-empty-objects', 200000, true, 'ArrayIterator-properties'],
        ];
    }

    #[DataProvider('siblingContainers')]
    public function testSiblingContainersFitWithinNativeMemoryLimit(
        string $shape,
        int $count,
        bool $includeNonFinite,
        string $nativeType = ''
    ): void {
        $native = $this->runSiblingMemoryProbe($shape, $count, 'native', $includeNonFinite, $nativeType);
        self::assertSame(0, $native['exit'], 'Native baseline failed: ' . $native['stderr']);
        $encoded = $this->runSiblingMemoryProbe($shape, $count, 'sdk', $includeNonFinite, $nativeType);
        self::assertSame(0, $encoded['exit'], 'SDK encoding failed: ' . $encoded['stderr']);
        $nativeLines = explode("\n", trim($native['stdout']));
        $encodedLines = explode("\n", trim($encoded['stdout']));
        $expected = json_decode(end($nativeLines), true, 512, JSON_THROW_ON_ERROR);
        $actual = json_decode(end($encodedLines), true, 512, JSON_THROW_ON_ERROR);
        self::assertLessThan(1024 * 1024, $expected['bytes']);
        self::assertSame($expected, $actual);
    }

    private function runSiblingMemoryProbe(
        string $shape,
        int $count,
        string $mode,
        bool $includeNonFinite,
        string $nativeType
    ): array {
        $process = proc_open(
            [PHP_BINARY, '-d', 'memory_limit=32M', '-d', 'max_execution_time=10',
                __DIR__ . '/fixtures/json-encoder-siblings.php', __DIR__ . '/../vendor/autoload.php',
                $shape, (string) $count, $mode, $includeNonFinite ? '1' : '0', $nativeType],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['exit' => proc_close($process), 'stdout' => $output, 'stderr' => $errors];
    }

    public static function largePayloadShapes(): array
    {
        return ['sibling allocation' => ['siblings', 10], 'reference traversal' => ['references', 5]];
    }

    #[DataProvider('largePayloadShapes')]
    public function testLargePayloadEncodingMatchesNativeWithinResourceLimits(string $shape, int $seconds): void
    {
        $outputs = [];
        foreach (['native', 'sdk'] as $mode) {
            $process = proc_open(
                [PHP_BINARY, '-d', 'memory_limit=128M', '-d', 'max_execution_time=' . $seconds,
                    __DIR__ . '/fixtures/json-encoder-scale.php', __DIR__ . '/../vendor/autoload.php', $shape, $mode],
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

    public function testTemporarySerializedObjectsFitWithinMemoryLimit(): void
    {
        $code = <<<'PHP'
        require $argv[1];
        $event = new class implements JsonSerializable {
            public function jsonSerialize(): mixed
            {
                $items = [];
                for ($i = 0; $i < 1000; ++$i) {
                    $items[] = (object) ['value' => $i];
                }
                return $items;
            }
        };
        $batch = array_fill(0, 100, $event);
        $native = json_encode($batch);
        if ($native === false || PostHog\JsonEncoder::encode($batch) !== $native) {
            exit(1);
        }
        echo 'encoded';
        PHP;
        $process = proc_open(
            [PHP_BINARY, '-d', 'memory_limit=64M', '-d', 'max_execution_time=10', '-r', $code,
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
        self::assertSame('encoded', $output);
    }

    public function testInvalidUtf8DoesNotChangeSerializerInvocationOrder(): void
    {
        $serializer = new class implements \JsonSerializable {
            public int $calls = 0;

            public function jsonSerialize(): mixed
            {
                ++$this->calls;
                return 'kept';
            }
        };
        self::assertFalse(JsonEncoder::encode(['invalid' => "\xB1", 'serializer' => $serializer]));
        self::assertSame(JSON_ERROR_UTF8, json_last_error());
        self::assertSame(0, $serializer->calls);
        self::assertFalse(JsonEncoder::encode(['serializer' => $serializer, 'invalid' => "\xB1"]));
        self::assertSame(JSON_ERROR_UTF8, json_last_error());
        self::assertSame(1, $serializer->calls);
    }

    public function testSharedInvalidArrayGraphIsRejectedWithoutEagerExpansion(): void
    {
        $code = <<<'PHP'
        require $argv[1];
        $value = ["\xB1"];
        for ($i = 0; $i < 24; ++$i) {
            $value = [$value, $value];
        }
        if (json_encode($value) !== false || json_last_error() !== JSON_ERROR_UTF8) {
            exit(1);
        }
        if (PostHog\JsonEncoder::encode($value) !== false || json_last_error() !== JSON_ERROR_UTF8) {
            exit(2);
        }
        echo 'rejected';
        PHP;
        $process = proc_open(
            [PHP_BINARY, '-d', 'memory_limit=32M', '-d', 'max_execution_time=2', '-r', $code,
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
