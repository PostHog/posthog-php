<?php

namespace PostHog\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PostHog\JsonEncoder;

class JsonEncoderDataOnlyTest extends TestCase
{
    public static function nonFiniteNumbers(): array
    {
        return ['NaN' => [NAN], 'positive infinity' => [INF], 'negative infinity' => [-INF]];
    }

    #[DataProvider('nonFiniteNumbers')]
    public function testDataOnlyNumbersBecomeNullWithoutChangingCallerValues(float $number): void
    {
        self::assertSame('null', JsonEncoder::encode($number));
        $input = ['number' => $number, 'nested' => ['items' => [false, $number, 0]], 'literal' => 'NaN'];
        self::assertSame(
            '{"number":null,"nested":{"items":[false,null,0]},"literal":"NaN"}',
            JsonEncoder::encode($input)
        );
        if (is_nan($number)) {
            self::assertNan($input['number']);
            self::assertNan($input['nested']['items'][1]);
        } else {
            self::assertSame($number, $input['number']);
            self::assertSame($number, $input['nested']['items'][1]);
        }
    }

    public function testOpaqueObjectDisablesRepairForTheWholePayload(): void
    {
        $serializer = new class implements \JsonSerializable {
            public int $calls = 0;

            public function jsonSerialize(): mixed
            {
                ++$this->calls;
                return 'kept';
            }
        };
        $input = ['number' => NAN, 'opaque' => $serializer];
        self::assertFalse(json_encode($input));
        $error = json_last_error();
        $nativeCalls = $serializer->calls;
        $serializer->calls = 0;
        self::assertFalse(JsonEncoder::encode($input));
        self::assertSame($error, json_last_error());
        self::assertSame($nativeCalls, $serializer->calls);
        self::assertNan($input['number']);
    }

    public function testReferenceSlotDisablesRepairForTheWholePayload(): void
    {
        $finite = 7;
        $input = ['number' => NAN, 'reference' => &$finite];
        $identity = \ReflectionReference::fromArrayElement($input, 'reference')->getId();
        self::assertFalse(json_encode($input));
        $error = json_last_error();
        self::assertFalse(JsonEncoder::encode($input));
        self::assertSame($error, json_last_error());
        self::assertSame($identity, \ReflectionReference::fromArrayElement($input, 'reference')->getId());
        self::assertSame(7, $finite);
        self::assertNan($input['number']);
    }

    public function testNativeSerializerFailureIsNotRetriedOrNormalized(): void
    {
        $serializer = new class implements \JsonSerializable {
            public int $calls = 0;

            public function jsonSerialize(): mixed
            {
                ++$this->calls;
                return ['number' => NAN];
            }
        };
        self::assertFalse(JsonEncoder::encode($serializer));
        self::assertSame(JSON_ERROR_INF_OR_NAN, json_last_error());
        self::assertSame(1, $serializer->calls);
    }

    public static function valueBudgets(): array
    {
        return ['within budget' => [9999, true], 'over budget' => [10000, false]];
    }

    #[DataProvider('valueBudgets')]
    public function testValueBudgetBoundary(int $count, bool $eligible): void
    {
        $input = array_fill(0, $count, 0);
        $input[0] = NAN;
        if ($eligible) {
            $expected = $input;
            $expected[0] = null;
            self::assertSame(json_encode($expected), JsonEncoder::encode($input));
        } else {
            self::assertFalse(JsonEncoder::encode($input));
            self::assertSame(JSON_ERROR_INF_OR_NAN, json_last_error());
        }
        self::assertNan($input[0]);
    }

    public static function byteBudgets(): array
    {
        return ['within budget' => [1048574, true], 'over budget' => [1048575, false]];
    }

    #[DataProvider('byteBudgets')]
    public function testStringAndKeyByteBudgetBoundary(int $bytes, bool $eligible): void
    {
        $input = ['n' => NAN, 's' => str_repeat('A', $bytes)];
        if ($eligible) {
            $expected = ['n' => null, 's' => $input['s']];
            self::assertTrue(
                JsonEncoder::encode($input) === json_encode($expected),
                'A payload at the byte boundary must be repaired exactly.'
            );
        } else {
            self::assertTrue(
                JsonEncoder::encode($input) === false,
                'An over-budget payload must retain native failure.'
            );
            self::assertSame(JSON_ERROR_INF_OR_NAN, json_last_error());
        }
        self::assertNan($input['n']);
    }

    public static function outsideBudgetShapes(): array
    {
        return ['values' => ['values'], 'bytes' => ['bytes'], 'depth' => ['depth']];
    }

    #[DataProvider('outsideBudgetShapes')]
    public function testFinitePayloadsOutsideRepairBudgetsStillUseNativeEncoding(string $shape): void
    {
        $input = match ($shape) {
            'values' => array_fill(0, 10000, 0),
            'bytes' => str_repeat('A', 1048577),
            'depth' => 0,
        };
        if ($shape === 'depth') {
            for ($i = 0; $i < 65; ++$i) {
                $input = [$input];
            }
        }
        $expected = json_encode($input);
        self::assertIsString($expected);
        self::assertTrue(JsonEncoder::encode($input) === $expected, 'Finite fallback must preserve native output.');
        self::assertSame(JSON_ERROR_NONE, json_last_error());
    }

    public static function eligibleMemoryShapes(): array
    {
        return ['dense non-finite arrays' => ['dense'], 'escaped string' => ['escaped']];
    }

    #[DataProvider('eligibleMemoryShapes')]
    public function testEligibleRepairMatchesNativeNullDataWithin32Mb(string $shape): void
    {
        $outputs = [];
        foreach (['native', 'sdk'] as $mode) {
            $process = proc_open(
                [PHP_BINARY, '-d', 'memory_limit=32M', '-d', 'max_execution_time=10',
                    __DIR__ . '/fixtures/json-encoder-data-only.php',
                    __DIR__ . '/../vendor/autoload.php', $shape, $mode],
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

    public function testRepairPreservesUnchangedPrefixesAndSparseKeys(): void
    {
        $input = [
            'before' => ['nested' => [2.5]],
            2 => 'kept',
            "\0name" => 0,
            5 => ['unchanged' => [], 'bad' => NAN, 'suffix' => false],
            'after' => [0, INF],
        ];
        $expected = $input;
        $expected[5]['bad'] = null;
        $expected['after'][1] = null;
        self::assertSame(json_encode($expected), JsonEncoder::encode($input));
        self::assertNan($input[5]['bad']);
        self::assertSame(INF, $input['after'][1]);
    }

    public function testRepairPreservesNumericStringKeysFromSymbolTables(): void
    {
        $input = (static function (): array {
            ${'0'} = NAN;
            ${'1'} = INF;
            return get_defined_vars();
        })();
        $expected = (static function (): array {
            ${'0'} = null;
            ${'1'} = null;
            return get_defined_vars();
        })();
        self::assertSame(['0', '1'], array_keys($input));
        self::assertSame('{"0":null,"1":null}', json_encode($expected));
        self::assertSame(json_encode($expected), JsonEncoder::encode($input));
        self::assertSame(['0', '1'], array_keys($input));
        self::assertNan(array_values($input)[0]);
        self::assertSame(INF, array_values($input)[1]);
    }

    public static function retainedCapacityShapes(): array
    {
        return ['pruned root' => ['root'], 'repeated children' => ['siblings'], 'sparse high key' => ['high-key']];
    }

    #[DataProvider('retainedCapacityShapes')]
    public function testRetainedArrayCapacityDoesNotExpandRepairAllocations(string $shape): void
    {
        $outputs = [];
        foreach (['native-error', 'native-null', 'sdk'] as $mode) {
            $process = proc_open(
                [PHP_BINARY, '-d', 'memory_limit=32M', '-d', 'max_execution_time=10',
                    __DIR__ . '/fixtures/json-encoder-retained-capacity.php',
                    __DIR__ . '/../vendor/autoload.php', $shape, $mode],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            self::assertIsResource($process);
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $mode . ': ' . $errors);
            $outputs[$mode] = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            if ($mode === 'native-error') {
                self::assertSame(['error' => JSON_ERROR_INF_OR_NAN], $outputs[$mode]);
            }
        }
        self::assertSame($outputs['native-null'], $outputs['sdk']);
    }

    public static function depthBudgets(): array
    {
        return ['within budget' => [64, true], 'over budget' => [65, false]];
    }

    #[DataProvider('depthBudgets')]
    public function testArrayDepthBudgetBoundary(int $depth, bool $eligible): void
    {
        $input = NAN;
        for ($i = 0; $i < $depth; ++$i) {
            $input = [$input];
        }
        if ($eligible) {
            self::assertSame(str_repeat('[', $depth) . 'null' . str_repeat(']', $depth), JsonEncoder::encode($input));
        } else {
            self::assertFalse(JsonEncoder::encode($input));
            self::assertSame(JSON_ERROR_INF_OR_NAN, json_last_error());
        }
    }
}
